<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config()->set('logging.channels.telemetry', ['driver' => 'telemetry', 'level' => 'debug']);

    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

function collectedLogEvents(CollectingExporter $collector): array
{
    Telemetry::flush();

    $events = [];

    foreach ($collector->batches() as $batch) {
        array_push($events, ...$batch->events);
    }

    return $events;
}

it('ships log records as telemetry events with severity mapping', function () {
    Log::channel('telemetry')->warning('Disk almost full', ['disk' => '/dev/sda1']);

    $events = collectedLogEvents($this->collector);

    expect($events)->toHaveCount(1)
        ->and($events[0]->name)->toBe('Disk almost full')
        ->and($events[0]->severityNumber)->toBe(13)
        ->and($events[0]->severityText)->toBe('WARNING')
        ->and($events[0]->attributes['log.context.disk'])->toBe('/dev/sda1')
        ->and($events[0]->attributes['log.channel'])->toBe('telemetry');
});

it('correlates log records to the active trace', function () {
    $span = Telemetry::span('work');

    Log::channel('telemetry')->info('halfway there');

    $span->end();

    $events = collectedLogEvents($this->collector);

    expect($events[0]->traceId)->toBe($span->traceId)
        ->and($events[0]->spanId)->toBe($span->spanId);
});

it('respects the channel level', function () {
    config()->set('logging.channels.telemetry.level', 'error');

    Log::channel('telemetry')->info('too quiet to matter');
    Log::channel('telemetry')->error('this one counts');

    $events = collectedLogEvents($this->collector);

    expect($events)->toHaveCount(1)
        ->and($events[0]->name)->toBe('this one counts')
        ->and($events[0]->severityNumber)->toBe(17);
});

it('flattens exceptions and non-scalar context', function () {
    Log::channel('telemetry')->error('Import failed', [
        'exception' => new RuntimeException('boom'),
        'rows' => ['a' => 1],
    ]);

    $attributes = collectedLogEvents($this->collector)[0]->attributes;

    expect($attributes['exception.type'])->toBe(RuntimeException::class)
        ->and($attributes['exception.message'])->toBe('boom')
        ->and($attributes['log.context.rows'])->toBe('{"a":1}');
});

it('routes log records to the active fake even if the channel was resolved first', function () {
    // Build + cache the telemetry channel against the real manager.
    Log::channel('telemetry')->info('warm up');

    $fake = Telemetry::fake();

    Log::channel('telemetry')->warning('after fake', ['disk' => '/dev/sda1']);

    $events = $fake->recordedEvents('after fake');

    expect($events)->toHaveCount(1)
        ->and($events[0]->attributes['log.context.disk'])->toBe('/dev/sda1');
});
