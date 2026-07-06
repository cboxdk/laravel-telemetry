<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\Livewire;

class LivewireInstrumentationTestCounter extends Component
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function render(): string
    {
        return '<div>{{ $count }}</div>';
    }
}

beforeEach(function () {
    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);

    Livewire::component('counter', LivewireInstrumentationTestCounter::class);
});

function livewireFamilies(): Collection
{
    return collect(Telemetry::collect())->keyBy(fn ($f) => $f->name());
}

function livewireSpans(CollectingExporter $collector, string $name): array
{
    return collect($collector->batches())
        ->flatMap(fn ($batch) => $batch->spans)
        ->filter(fn ($span) => $span->name === $name)
        ->values()
        ->all();
}

it('counts component mounts by component name', function () {
    Livewire::test('counter');

    $samples = livewireFamilies()['livewire.components.mounted']->samples;

    expect($samples[0]->labels['livewire.component'])->toBe('counter')
        ->and($samples[0]->value)->toBe(1.0);
});

it('counts a rehydrated component on a subsequent request', function () {
    Livewire::test('counter')->call('increment');

    expect(livewireFamilies()['livewire.components.hydrated']->samples[0]->value)->toBe(1.0);
});

it('spans a render inside a sampled trace, tallying the root span regardless', function () {
    Telemetry::span('root', function () {
        Livewire::test('counter');
    });
    Telemetry::flush();

    $spans = livewireSpans($this->collector, 'livewire.render');

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->attributes()['livewire.component'])->toBe('counter')
        ->and($spans[0]->isDetail())->toBeTrue();

    $root = collect($this->collector->batches())->flatMap(fn ($batch) => $batch->spans)
        ->first(fn ($span) => $span->parentSpanId === null);

    expect($root->attributes()['livewire.render.count'])->toBe(1);
});

it('spans a property update with the property name', function () {
    Telemetry::span('root', function () {
        Livewire::test('counter')->set('count', 5);
    });
    Telemetry::flush();

    $spans = livewireSpans($this->collector, 'livewire.update');

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->attributes()['livewire.component'])->toBe('counter')
        ->and($spans[0]->attributes()['livewire.property'])->toBe('count');
});

it('spans a method call with the method name', function () {
    Telemetry::span('root', function () {
        Livewire::test('counter')->call('increment');
    });
    Telemetry::flush();

    $spans = livewireSpans($this->collector, 'livewire.call');

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->attributes()['livewire.component'])->toBe('counter')
        ->and($spans[0]->attributes()['livewire.method'])->toBe('increment');
});

it('creates no detail spans outside a sampled trace, but keeps counting mounts', function () {
    Livewire::test('counter')->call('increment');
    Telemetry::flush();

    expect(livewireSpans($this->collector, 'livewire.render'))->toBeEmpty()
        ->and(livewireSpans($this->collector, 'livewire.call'))->toBeEmpty()
        ->and(livewireFamilies()['livewire.components.mounted']->samples[0]->value)->toBe(1.0);
});

it('collects touched component names on the request for route naming', function () {
    Livewire::test('counter')->call('increment');

    // TraceRequest::terminate() derives "livewire:counter" from this.
    expect(request()->attributes->get('telemetry.livewire.components'))->toContain('counter');
});
