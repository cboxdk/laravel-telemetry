<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanStatus;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

function exceptionEvents(CollectingExporter $c): Collection
{
    return collect($c->batches())->flatMap(fn ($b) => $b->events)->filter(fn ($e) => $e->name === 'exception')->values();
}

it('emits a structured, fingerprinted exception record on report()', function () {
    report(new RuntimeException('kaboom'));
    Telemetry::flush();

    $event = exceptionEvents($this->collector)->first();

    expect($event)->not->toBeNull()
        ->and($event->severityText)->toBe('ERROR')
        ->and($event->severityNumber)->toBe(17)
        ->and($event->attributes['exception.type'])->toBe(RuntimeException::class)
        ->and($event->attributes['exception.message'])->toBe('kaboom')
        ->and($event->attributes)->toHaveKey('exception.group')
        ->and($event->attributes)->toHaveKey('exception.file')
        ->and($event->attributes)->toHaveKey('exception.line');

    expect(collect(Telemetry::collect())->keyBy(fn ($f) => $f->name()))->toHaveKey('exceptions.reported');
});

it('stamps the authenticated user on the exception record', function () {
    $user = new class extends User
    {
        protected $table = 'users';

        public function getAuthIdentifier()
        {
            return 42;
        }
    };

    auth()->setUser($user);

    report(new RuntimeException('who hit it'));
    Telemetry::flush();

    expect(exceptionEvents($this->collector)->first()->attributes['enduser.id'])->toBe('42');
});

it('omits enduser.id for guests', function () {
    report(new RuntimeException('anonymous'));
    Telemetry::flush();

    expect(exceptionEvents($this->collector)->first()->attributes)->not->toHaveKey('enduser.id');
});

it('carries ambient context onto the exception record', function () {
    Telemetry::context(['team.id' => 'acme']);

    report(new RuntimeException('with context'));
    Telemetry::flush();

    expect(exceptionEvents($this->collector)->first()->attributes['team.id'])->toBe('acme');
});

it('records a rich exception event once on a span and deduplicates', function () {
    $e = new RuntimeException('span boom');

    $span = Telemetry::span('work');
    $span->recordException($e);
    $span->recordException($e); // same exception -> deduped
    $span->end();
    Telemetry::flush();

    $spans = collect($this->collector->batches())->flatMap(fn ($b) => $b->spans);
    $work = $spans->firstWhere('name', 'work');
    $exEvents = collect($work->events())->filter(fn ($ev) => $ev->name === 'exception');

    expect($exEvents)->toHaveCount(1)
        ->and($exEvents->first()->attributes)->toHaveKey('exception.group')
        ->and($work->status())->toBe(SpanStatus::Error);
});

it('annotates but does not fail the active span for a handled report()', function () {
    Telemetry::span('handled', function () {
        report(new RuntimeException('handled + recovered'));

        return 'ok';
    });
    Telemetry::flush();

    $span = collect($this->collector->batches())->flatMap(fn ($b) => $b->spans)->firstWhere('name', 'handled');

    // The span carries the exception event but stays OK (the request succeeded).
    expect(collect($span->events())->contains(fn ($e) => $e->name === 'exception'))->toBeTrue()
        ->and($span->status())->not->toBe(SpanStatus::Error);
});
