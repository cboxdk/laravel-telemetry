<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

/**
 * An immutable set of telemetry signals.
 */
final readonly class SignalSet
{
    /** @var list<Signal> */
    private array $signals;

    public function __construct(Signal ...$signals)
    {
        $this->signals = array_values(array_unique($signals, SORT_REGULAR));
    }

    public static function all(): self
    {
        return new self(...Signal::cases());
    }

    public static function of(Signal ...$signals): self
    {
        return new self(...$signals);
    }

    public function contains(Signal $signal): bool
    {
        return in_array($signal, $this->signals, true);
    }

    /**
     * @return list<Signal>
     */
    public function toArray(): array
    {
        return $this->signals;
    }
}
