<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\TelemetryManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory;

/**
 * Factory decorator: wraps whatever `connection()` resolves in an
 * InstrumentedBroadcaster, driver-agnostic. Everything else (queue(),
 * routes(), channel(), …) forwards untouched to the real manager — the
 * concrete BroadcastManager has public methods well beyond the Factory
 * contract that app code and the framework itself (Dispatcher::
 * broadcastEvent() calls ->queue()) rely on.
 */
final readonly class InstrumentedBroadcastManager implements Factory
{
    public function __construct(
        private Factory $manager,
        private TelemetryManager $telemetry,
    ) {}

    public function connection($name = null): Broadcaster
    {
        $broadcaster = $this->manager->connection($name);

        if ($broadcaster instanceof InstrumentedBroadcaster) {
            return $broadcaster;
        }

        return new InstrumentedBroadcaster($broadcaster, $this->telemetry, $this->connectionName($name));
    }

    private function connectionName(mixed $name): string
    {
        if (is_string($name) && $name !== '') {
            return $name;
        }

        if ($name instanceof \BackedEnum) {
            return (string) $name->value;
        }

        if ($name instanceof \UnitEnum) {
            return $name->name;
        }

        $default = config('broadcasting.default');

        return is_string($default) && $default !== '' ? $default : 'null';
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->manager->{$method}(...$arguments);
    }
}
