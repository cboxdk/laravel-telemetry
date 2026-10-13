<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Testing;

use Cbox\Telemetry\Facades\Telemetry;
use Closure;
use LogicException;
use PHPUnit\Framework\Assert;

/**
 * Pest expectations for performance budgets — opt-in, because Pest is not
 * a dependency of this package. Register them once, in `tests/Pest.php`:
 *
 * ```php
 * \Cbox\Telemetry\Testing\PestExpectations::register();
 *
 * it('lists orders cheaply', function () {
 *     Telemetry::fake();
 *
 *     expect(fn () => $this->get('/orders'))->toStayWithinBudget(queries: 10, ms: 200);
 * });
 * ```
 *
 * Each expectation runs the closure once, through `TelemetryFake::budget()`.
 */
final class PestExpectations
{
    public static function register(): void
    {
        if (! function_exists('expect')) {
            throw new LogicException('PestExpectations::register() needs Pest (pestphp/pest); call it from tests/Pest.php.');
        }

        (new self)->extend();
    }

    /**
     * Run an expectation's value through a budget on the active fake.
     */
    public static function budget(
        mixed $value,
        ?int $queries = null,
        ?float $ms = null,
        bool $allowNPlusOne = false,
        bool $allowDuplicates = false,
    ): void {
        if (! $value instanceof Closure) {
            throw new LogicException('Performance expectations take a closure: expect(fn () => $this->get(\'/orders\')).');
        }

        $fake = Telemetry::getFacadeRoot();

        if (! $fake instanceof TelemetryFake) {
            Assert::fail('Performance expectations read the telemetry fake: call Telemetry::fake() first.');
        }

        $fake->budget(static fn (): mixed => $value(), $queries, $ms, $allowNPlusOne, $allowDuplicates);
    }

    private function extend(): void
    {
        // Pest binds each closure to the Expectation, so `$this->value` is
        // the closure under test at call time.
        expect()->extend('toStayWithinBudget', function (?int $queries = null, ?float $ms = null, bool $allowNPlusOne = false, bool $allowDuplicates = false) {
            PestExpectations::budget($this->value, $queries, $ms, $allowNPlusOne, $allowDuplicates);

            return $this;
        });

        expect()->extend('toQueryAtMost', function (int $queries) {
            PestExpectations::budget($this->value, $queries, allowNPlusOne: true, allowDuplicates: true);

            return $this;
        });

        expect()->extend('toHaveNoNPlusOne', function () {
            PestExpectations::budget($this->value, allowDuplicates: true);

            return $this;
        });

        expect()->extend('toHaveNoDuplicateQueries', function () {
            PestExpectations::budget($this->value, allowNPlusOne: true);

            return $this;
        });
    }
}
