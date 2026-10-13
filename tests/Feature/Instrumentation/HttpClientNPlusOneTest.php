<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\HttpClientRepeatDetector;
use Cbox\Telemetry\Instrumentation\HttpClientSpanMiddleware;
use Cbox\Telemetry\Instrumentation\QueryInstrumentation;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\Span;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Telemetry::fake();
});

/**
 * A fresh client factory with exactly one span middleware, configured by the
 * test — the one the provider registered at boot carries the defaults.
 */
function nPlusOneClient(?HttpClientRepeatDetector $repeats = new HttpClientRepeatDetector, string $origin = QueryInstrumentation::ORIGIN_FLAGGED): void
{
    $factory = new Factory;
    $factory->globalMiddleware(new HttpClientSpanMiddleware(app(), $repeats, $origin, dirname(__DIR__, 3)));
    Http::swap($factory);
    Http::fake(['*' => Http::response(['ok' => true])]);
}

/**
 * @return list<Span>
 */
function clientSpans(): array
{
    return array_values(array_filter(
        Telemetry::recordedSpans(),
        fn (Span $span) => str_starts_with($span->name, 'GET ') || str_starts_with($span->name, 'POST '),
    ));
}

function rootSpanAttributes(): array
{
    foreach (Telemetry::recordedSpans('request') as $span) {
        return $span->attributes();
    }

    return [];
}

it('flags one endpoint called once per id, one call after another', function () {
    nPlusOneClient();

    Telemetry::span('request', function () {
        foreach ([1, 2, 3, 4] as $id) {
            Http::get("https://api.example.test/users/{$id}?include=team");
        }
    });

    $spans = clientSpans();

    expect($spans)->toHaveCount(4)
        ->and(collect($spans)->map(fn (Span $s) => $s->attributes()['laravel.http.client.shape'])->unique())->toHaveCount(1)
        ->and($spans[0]->attributes()['url.template'])->toBe('/users/{id}')
        ->and($spans[0]->attributes())->not->toHaveKey('laravel.http.client.repeat')
        ->and($spans[0]->attributes())->not->toHaveKey('code.file.path')
        ->and($spans[2]->attributes()['laravel.http.client.repeat'])->toBe('n_plus_one')
        ->and($spans[2]->attributes()['code.file.path'])->toBe('tests/Feature/Instrumentation/HttpClientNPlusOneTest.php')
        ->and($spans[2]->attributes()['code.line.number'])->toBeInt()
        ->and($spans[3]->attributes()['laravel.http.client.repeat'])->toBe('n_plus_one')
        ->and($spans[3]->attributes())->not->toHaveKey('code.file.path');

    $events = Telemetry::recordedEvents('http.client.n_plus_one_detected');

    expect($events)->toHaveCount(1)
        ->and($events[0]->attributes['http.client.repeat_count'])->toBe(3)
        ->and($events[0]->attributes['http.request.method'])->toBe('GET')
        ->and($events[0]->attributes['server.address'])->toBe('api.example.test')
        ->and($events[0]->attributes['url.template'])->toBe('/users/{id}')
        ->and($events[0]->attributes['url.full'])->toBe('https://api.example.test/users/3?include=team')
        ->and($events[0]->attributes['laravel.http.client.shape'])->toHaveLength(16)
        ->and($events[0]->attributes['code.file.path'])->toBe('tests/Feature/Instrumentation/HttpClientNPlusOneTest.php');

    expect(rootSpanAttributes()['http.client.n_plus_one.count'])->toBe(1);

    Telemetry::assertCounterIncremented('http.client.requests.n_plus_one', ['server.address' => 'api.example.test']);
});

it('is on by default through the boot-registered middleware', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    Telemetry::span('request', function () {
        foreach ([1, 2, 3] as $id) {
            Http::get("https://api.example.test/users/{$id}");
        }
    });

    Telemetry::assertEventEmitted('http.client.n_plus_one_detected');
});

it('does not call the same URL again an N+1', function () {
    nPlusOneClient();

    Telemetry::span('request', function () {
        foreach ([1, 2, 3, 4] as $_) {
            Http::get('https://api.example.test/users/1');
        }
    });

    Telemetry::assertEventNotEmitted('http.client.n_plus_one_detected');

    expect(rootSpanAttributes())->not->toHaveKey('http.client.n_plus_one.count');
});

it('does not call requests sent together through a pool an N+1', function () {
    nPlusOneClient();

    Telemetry::span('request', function () {
        Http::pool(fn (Pool $pool) => array_map(
            fn (int $id) => $pool->get("https://api.example.test/users/{$id}"),
            [1, 2, 3, 4, 5],
        ));
    });

    expect(clientSpans())->toHaveCount(5);

    Telemetry::assertEventNotEmitted('http.client.n_plus_one_detected');
});

it('does not call pagination an N+1', function () {
    nPlusOneClient();

    Telemetry::span('request', function () {
        foreach ([1, 2, 3, 4] as $page) {
            Http::get("https://api.example.test/users?page={$page}");
        }

        foreach (['a', 'b', 'c', 'd'] as $cursor) {
            Http::get("https://api.example.test/teams?cursor={$cursor}&limit=50");
        }
    });

    Telemetry::assertEventNotEmitted('http.client.n_plus_one_detected');
});

it('does not call a loop of writes an N+1', function () {
    nPlusOneClient();

    Telemetry::span('request', function () {
        foreach ([1, 2, 3, 4] as $id) {
            Http::post("https://api.example.test/users/{$id}/notify");
        }
    });

    Telemetry::assertEventNotEmitted('http.client.n_plus_one_detected');
});

it('resets between traces', function () {
    nPlusOneClient();

    Telemetry::span('request', function () {
        Http::get('https://api.example.test/users/1');
        Http::get('https://api.example.test/users/2');
    });

    Telemetry::resetContext();

    Telemetry::span('request', function () {
        Http::get('https://api.example.test/users/3');
    });

    Telemetry::assertEventNotEmitted('http.client.n_plus_one_detected');
});

it('respects a custom threshold', function () {
    nPlusOneClient(new HttpClientRepeatDetector(threshold: 5));

    Telemetry::span('request', function () {
        foreach ([1, 2, 3, 4] as $id) {
            Http::get("https://api.example.test/users/{$id}");
        }
    });

    Telemetry::assertEventNotEmitted('http.client.n_plus_one_detected');
});

it('shapes nothing when detection is off', function () {
    nPlusOneClient(repeats: null);

    Telemetry::span('request', function () {
        foreach ([1, 2, 3, 4] as $id) {
            Http::get("https://api.example.test/users/{$id}");
        }
    });

    Telemetry::assertEventNotEmitted('http.client.n_plus_one_detected');

    foreach (clientSpans() as $span) {
        expect($span->attributes())->not->toHaveKey('laravel.http.client.shape')
            ->and($span->attributes())->not->toHaveKey('url.template')
            ->and($span->attributes())->not->toHaveKey('code.file.path');
    }
});

it('attaches an origin to every client span in all mode, and none when off', function (string $origin, bool $expected) {
    nPlusOneClient(origin: $origin);

    Telemetry::span('request', function () {
        foreach ([1, 2, 3] as $id) {
            Http::get("https://api.example.test/users/{$id}");
        }
    });

    foreach (clientSpans() as $span) {
        expect(isset($span->attributes()['code.file.path']))->toBe($expected);
    }

    Telemetry::assertEventEmitted(
        'http.client.n_plus_one_detected',
        fn ($event) => isset($event->attributes['code.file.path']) === $expected,
    );
})->with([
    'all' => [QueryInstrumentation::ORIGIN_ALL, true],
    'off' => [QueryInstrumentation::ORIGIN_OFF, false],
]);

it('keeps the detail spans of a trace with an HTTP N+1 in tail mode', function () {
    config()->set('telemetry.traces.details.mode', 'tail');
    $this->refreshApplication();
    config()->set('telemetry.traces.details.mode', 'tail');
    Telemetry::addExporter($collector = new CollectingExporter);
    nPlusOneClient();

    Telemetry::span('request', function () {
        Telemetry::tracer()->recordSpan('cache.get', 0.1, detail: true);

        foreach ([1, 2, 3] as $id) {
            Http::get("https://api.example.test/users/{$id}");
        }
    });

    Telemetry::flush();

    $names = collect($collector->batches())->flatMap(fn ($batch) => $batch->spans)->map(fn (Span $s) => $s->name);

    expect($names)->toContain('cache.get');
});
