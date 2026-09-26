<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Contracts;

use Cbox\Telemetry\Events\TelemetryEvent;
use Cbox\Telemetry\Support\Redactor;
use Cbox\Telemetry\Tracing\Span;
use Closure;

/**
 * The last hands on every attribute value before an exporter sees it.
 *
 * A contract rather than a class because redaction is the one policy an
 * organisation is most likely to have its own opinion about — a
 * compliance team with a list, a shared internal package, a scanner that
 * already exists elsewhere in the estate. Bind your own implementation
 * and the whole pipeline uses it:
 *
 *     $this->app->bind(RedactsTelemetry::class, AcmeRedactor::class);
 *
 * The built-in {@see Redactor} is configured
 * entirely from `telemetry.redaction`, so most needs are a config change
 * rather than a class. Replace it when you need a different MODEL, not
 * a different list.
 *
 * Whatever implements this must never throw: it runs inside the flush
 * path, which is guarded, but a redactor that fails is a redactor that
 * has not redacted, and the batch goes out either way.
 */
interface RedactsTelemetry
{
    /**
     * Redact a batch of spans, including their events and links.
     *
     * @param  list<Span>  $spans
     * @return list<Span>
     */
    public function spans(array $spans): array;

    /**
     * @param  list<TelemetryEvent>  $events
     * @return list<TelemetryEvent>
     */
    public function events(array $events): array;

    /**
     * Redact a structure on the way IN — used by the log channel and by
     * anything handing this package a nested payload, so that key-based
     * rules can see keys the flattened attribute name would have lost.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function redactStructure(array $values, int $depth = 0): array;

    /**
     * An application hook, run after the built-in rules.
     *
     * @param  (Closure(string, string): ?string)|null  $custom
     */
    public function redactUsing(?Closure $custom): void;

    /**
     * Whether this attribute KEY names something sensitive.
     */
    public function keyIsSensitive(string $key): bool;

    /**
     * Whether this VALUE looks like a credential whatever it was called.
     *
     * The half of the model that matters for auto-instrumentation: a
     * name list only catches parameters someone thought of, and the URLs
     * an application calls are full of ones nobody did.
     */
    public function valueLooksLikeCredential(string $value): bool;
}
