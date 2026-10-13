<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\Support\HttpMethod;
use Cbox\Telemetry\Support\HttpShape;
use Cbox\Telemetry\TelemetryManager;
use Psr\Http\Message\RequestInterface;

/**
 * N+1 for outgoing HTTP: one endpoint called over and over with a
 * different id, one call after another, inside one trace — a loop that
 * fetches a related resource per row instead of asking for them together.
 *
 * The twin of QueryInstrumentation's N+1 detection, with the same rules
 * where they carry over:
 *
 *  - grouped by SHAPE (HttpShape): method, host, path with ids taken out,
 *    query parameter names without their values;
 *  - reads only (GET/HEAD) — a loop of POSTs is a missing bulk endpoint;
 *  - pagination is not N+1 (`?page=`, `?cursor=`, `/page/2`);
 *  - the URL has to VARY: the same URL again is a retry or a missing
 *    cache, not a per-row fetch;
 *  - scoped by trace id, bounded, fired once per shape per trace at the
 *    threshold crossing.
 *
 * One rule is HTTP's own: the calls have to be SEQUENTIAL. Ten requests
 * sent together through `Http::pool` are the fix for an N+1, not one, so
 * a hop that starts while another of its shape is still in flight is not
 * counted.
 */
final class HttpClientRepeatDetector
{
    public const REPEAT_N_PLUS_ONE = 'n_plus_one';

    /** Distinct shapes held per trace before the map resets. */
    private const MAX_SHAPES = 10_000;

    private ?string $currentTraceId = null;

    /**
     * Per shape: sequential occurrences, the first exact call seen, whether
     * a different one has turned up since, whether it was reported, and how
     * many hops of it are in flight right now.
     *
     * @var array<string, array{count: int, first: string, varied: bool, reported: bool, inFlight: int}>
     */
    private array $shapes = [];

    private readonly int $threshold;

    public function __construct(int $threshold = 3)
    {
        $this->threshold = max(2, $threshold);
    }

    /**
     * Account for a hop that is starting: its shape attributes, whether it
     * is the one that crossed the threshold, and how many sequential calls
     * of its shape this trace has made. Null outside a trace.
     *
     * @return array{shape: string, attributes: array<string, string>, crossed: bool, count: int}|null
     */
    public function enter(TelemetryManager $telemetry, RequestInterface $request): ?array
    {
        $traceId = $telemetry->traceId();

        if ($traceId === null) {
            return null;
        }

        if ($traceId !== $this->currentTraceId) {
            $this->currentTraceId = $traceId;
            $this->shapes = [];
        }

        if (count($this->shapes) >= self::MAX_SHAPES) {
            $this->shapes = [];
        }

        $uri = $request->getUri();
        $method = $request->getMethod();
        $authority = self::authority($request);
        $path = $uri->getPath();
        $query = $uri->getQuery();

        $shape = HttpShape::hash($method, $authority, $path, $query);
        $attributes = [
            'laravel.http.client.shape' => $shape,
            'url.template' => HttpShape::template($path),
        ];

        if (! HttpShape::isRead($method) || HttpShape::isPagination($path, $query)) {
            return ['shape' => $shape, 'attributes' => $attributes, 'crossed' => false, 'count' => 0];
        }

        $exact = strtoupper($method).' '.$uri;
        $state = $this->shapes[$shape] ?? ['count' => 0, 'first' => $exact, 'varied' => false, 'reported' => false, 'inFlight' => 0];

        $crossed = false;

        // Sequential only: a hop that starts beside one of its own shape is
        // part of a batch sent together, which is the cure, not the smell.
        if ($state['inFlight'] === 0) {
            $state['count']++;
            $state['varied'] = $state['varied'] || $exact !== $state['first'];

            if (! $state['reported'] && $state['varied'] && $state['count'] >= $this->threshold) {
                $state['reported'] = $crossed = true;
            }
        }

        $state['inFlight']++;
        $this->shapes[$shape] = $state;

        if ($state['reported']) {
            $attributes['laravel.http.client.repeat'] = self::REPEAT_N_PLUS_ONE;
        }

        return ['shape' => $shape, 'attributes' => $attributes, 'crossed' => $crossed, 'count' => $state['count']];
    }

    /**
     * A hop has settled. A shape from a trace that has since ended is
     * gone from the map, and there is nothing to release.
     */
    public function leave(string $shape): void
    {
        if (isset($this->shapes[$shape]) && $this->shapes[$shape]['inFlight'] > 0) {
            $this->shapes[$shape]['inFlight']--;
        }
    }

    /**
     * The tally, counter and event for a shape that just crossed.
     *
     * @param  array<string, string|int>  $origin
     */
    public function report(TelemetryManager $telemetry, RequestInterface $request, string $shape, int $count, array $origin): void
    {
        FailSafe::guard(function () use ($telemetry, $request, $shape, $count, $origin) {
            $telemetry->tracer()->bumpStat('http.client.n_plus_one.count', 1);

            $uri = $request->getUri();
            $host = $uri->getHost() !== '' ? $uri->getHost() : 'unknown';

            // Bounded by the same classifier as the duration histogram: the
            // span keeps the hostname, the metric takes the app's grouping.
            [$record, $label] = $telemetry->classifyHttpHost($host);

            if ($record) {
                $telemetry->counter('http.client.requests.n_plus_one', 'Distinct outgoing request shapes called repeatedly, one after another, with different values within one trace (N+1)')
                    ->inc(1, ['server.address' => $label]);
            }

            $telemetry->event('http.client.n_plus_one_detected', [
                'http.request.method' => HttpMethod::normalize($request->getMethod()),
                'server.address' => $host,
                'url.template' => HttpShape::template($uri->getPath()),
                'url.full' => (string) $uri,
                'laravel.http.client.shape' => $shape,
                'http.client.repeat_count' => $count,
                ...$origin,
            ]);
        });
    }

    private static function authority(RequestInterface $request): string
    {
        $uri = $request->getUri();
        $host = $uri->getHost() !== '' ? $uri->getHost() : 'unknown';

        return $uri->getPort() !== null ? $host.':'.$uri->getPort() : $host;
    }
}
