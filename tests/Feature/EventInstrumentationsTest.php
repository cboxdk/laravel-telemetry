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
