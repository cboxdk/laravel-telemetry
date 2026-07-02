<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Http\Middleware;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-route sampling overrides:
 *
 *     Route::get('/health', ...)->middleware(Sample::never());
 *     Route::get('/checkout', ...)->middleware(Sample::always());
 *     Route::get('/feed', ...)->middleware(Sample::rate(0.01));
 *
 * The re-decision applies to the whole active trace from this point —
 * including the still-open request span. Error spans still escape
 * sampling when `traces.always_sample_errors` is on.
 */
final class Sample
{
    public function __construct(private readonly TelemetryManager $telemetry) {}

    public static function rate(float $rate): string
    {
        return self::class.':'.$rate;
    }

    public static function always(): string
    {
        return self::class.':1';
    }

    public static function never(): string
    {
        return self::class.':0';
    }

    public function handle(Request $request, Closure $next, string $rate = '1'): Response
    {
        FailSafe::guard(function () use ($rate) {
            if (is_numeric($rate)) {
                $this->telemetry->tracer()->resampleAt((float) $rate);
            }
        });

        return $next($request);
    }
}
