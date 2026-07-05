<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Instrumentation\CacheInstrumentation;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

it('counts cache operations without key labels', function () {
    config()->set('telemetry.instrument.cache', true);

    // Re-register with cache instrumentation enabled.
    app(CacheInstrumentation::class)->register(app('events'));

    Cache::put('a-key', 'value', 60);
    Cache::get('a-key');
    Cache::get('missing-key');
    Cache::forget('a-key');

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKey('cache.operations');

    $operations = collect($families['cache.operations']->samples)
        ->mapWithKeys(fn ($sample) => [$sample->labels['operation'] => $sample->value]);

    expect($operations['hit'])->toBeGreaterThanOrEqual(1)
        ->and($operations['miss'])->toBeGreaterThanOrEqual(1)
        ->and($operations['write'])->toBeGreaterThanOrEqual(1)
        ->and($operations['forget'])->toBeGreaterThanOrEqual(1);

    // No key label anywhere — cardinality safety.
    foreach ($families['cache.operations']->samples as $sample) {
        expect($sample->labels)->not->toHaveKey('key');
    }
});

it('spans and counts notifications', function () {
    $notifiable = new class
    {
        use Notifiable;

        public int $id = 1;

        public function routeNotificationForMail(): string
        {
            return 'demo@example.com';
        }
    };

    $notification = new class extends Illuminate\Notifications\Notification
    {
        public function via(object $notifiable): array
        {
            return ['mail'];
        }

        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)->subject('Hello')->line('Hi');
        }
    };

    config()->set('mail.default', 'array');

    Notification::sendNow($notifiable, $notification);

    Telemetry::flush();

    $spans = collect($this->collector->batches())->flatMap(fn ($batch) => $batch->spans);

    expect($spans->firstWhere('name', 'notification.send'))->not->toBeNull()
        ->and($spans->firstWhere('name', 'mail.send'))->not->toBeNull();

    $families = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name());

    expect($families)->toHaveKeys(['notifications.sent', 'mail.sent'])
        ->and($families['notifications.sent']->samples[0]->labels['channel'])->toBe('mail');
});

it('records the bootstrap phase when LARAVEL_START is defined', function () {
    if (! defined('LARAVEL_START')) {
        $this->markTestSkipped('LARAVEL_START is not defined in this runtime.');
    }

    Route::get('/boot', fn () => 'ok');

    $this->get('/boot')->assertOk();

    Telemetry::flush();

    $spans = collect($this->collector->batches())->flatMap(fn ($batch) => $batch->spans);

    expect($spans->firstWhere('name', 'laravel.bootstrap'))->not->toBeNull();
});

it('records nightwatch-style cache spans with key, store and duration', function () {
    config()->set('telemetry.instrument.cache_spans', true);

    (new CacheInstrumentation(app()))->register(app('events'), counters: false, spans: true);

    Telemetry::span('request-ish', function () {
        Cache::put('request_count:117797', 42, 60);
        Cache::get('request_count:117797');
        Cache::get('missing:key');
        Cache::forget('request_count:117797');
    });

    Telemetry::flush();

    $spans = collect($this->collector->batches())->flatMap(fn ($batch) => $batch->spans);

    $hit = $spans->firstWhere('name', 'cache.hit');
    $miss = $spans->firstWhere('name', 'cache.miss');
    $write = $spans->firstWhere('name', 'cache.write');
    $forget = $spans->firstWhere('name', 'cache.forget');

    expect($hit->attributes()['cache.key'])->toBe('request_count:117797')
        ->and($hit->attributes())->toHaveKey('cache.store')
        ->and($hit->durationMs())->toBeGreaterThanOrEqual(0)
        ->and($miss->attributes()['cache.key'])->toBe('missing:key')
        ->and($write)->not->toBeNull()
        ->and($forget)->not->toBeNull();

    // All parented into the surrounding trace.
    $root = $spans->firstWhere('name', 'request-ish');

    expect($hit->traceId)->toBe($root->traceId)
        ->and($hit->parentSpanId)->toBe($root->spanId);

    // Tallies on the root span.
    expect($root->attributes()['cache.event.count'])->toBe(4);
});

it('records no cache spans outside a sampled trace', function () {
    (new CacheInstrumentation(app()))->register(app('events'), counters: false, spans: true);

    Cache::get('orphan-key');

    Telemetry::flush();

    $spans = collect($this->collector->batches())->flatMap(fn ($batch) => $batch->spans);

    expect($spans->firstWhere('name', 'cache.miss'))->toBeNull();
});

it('classifies cache keys into bounded groups and drops null-classified operations', function () {
    (new CacheInstrumentation(app()))->register(app('events'), counters: true, spans: true);

    Telemetry::classifyCacheKeysUsing(function (string $store, string $key) {
        if (str_starts_with($key, 'stache::indexes::')) {
            return 'stache.index';
        }

        return str_starts_with($key, 'noise:') ? null : 'app';
    });

    Telemetry::span('request-ish', function () {
        Cache::get('stache::indexes::collections::blog::slug');
        Cache::get('users:7');
        Cache::get('noise:heartbeat');
    });

    Telemetry::flush();

    $spans = collect($this->collector->batches())->flatMap(fn ($batch) => $batch->spans);

    expect($spans->firstWhere(fn ($span) => ($span->attributes()['cache.key.group'] ?? null) === 'stache.index'))->not->toBeNull()
        ->and($spans->firstWhere(fn ($span) => ($span->attributes()['cache.key'] ?? null) === 'noise:heartbeat'))->toBeNull();

    $groups = collect(Telemetry::collect())->keyBy(fn ($family) => $family->name())['cache.operations']->samples;

    expect(collect($groups)->pluck('labels.key_group')->all())->toContain('stache.index', 'app')
        ->and(collect($groups)->sum('value'))->toBe(2.0);
});

it('ignores configured cache stores entirely', function () {
    (new CacheInstrumentation(app()))->register(app('events'), counters: true, spans: true, ignoreStores: ['array']);

    Telemetry::span('request-ish', fn () => Cache::get('anything'));
    Telemetry::flush();

    $spans = collect($this->collector->batches())->flatMap(fn ($batch) => $batch->spans);

    expect($spans->firstWhere('name', 'cache.miss'))->toBeNull()
        ->and(collect(Telemetry::collect())->keyBy(fn ($family) => $family->name()))->not->toHaveKey('cache.operations');
});
