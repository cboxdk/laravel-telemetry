<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Http\Controllers;

use Cbox\Telemetry\Directives\Directive;
use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * The directives control plane over HTTP, for a telemetry UI or insights
 * install that is not this application.
 *
 * Every call is signed: `X-Telemetry-Timestamp: <unix>` and
 * `X-Telemetry-Signature: sha256=<hex hmac>` with `telemetry.directives.secret`
 * over `"<timestamp>.<METHOD>.<directive id or empty>.<raw body>"`.
 *
 * The method and the id are in the signed string so a captured list call
 * cannot be replayed as a delete of anything, and each signature is
 * accepted once: a captured add cannot be replayed to fill the list.
 * Five minutes of clock skew, no more. The route only exists when a
 * secret is configured.
 */
final class DirectivesController
{
    private const MAX_SKEW_SECONDS = 300;

    public function __construct(private readonly TelemetryManager $telemetry) {}

    public function index(Request $request): JsonResponse
    {
        return $this->signed($request, '', fn (): JsonResponse => new JsonResponse([
            'directives' => array_map(static fn (Directive $d): array => $d->toArray(), $this->telemetry->directives()->all()),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->signed($request, '', function () use ($request): JsonResponse {
            $data = $request->json()->all();

            // `minutes` is the friendly form; the stored one is absolute.
            if (is_numeric($data['minutes'] ?? null)) {
                $data['expires_at'] = time() + max(1, Cast::int($data['minutes'])) * 60;
            }

            try {
                // The id is always ours: a caller choosing it could
                // overwrite a directive it did not create.
                $directive = Directive::fromArray(['id' => null] + $data);
                $directive = $this->telemetry->directives()->add($directive);
            } catch (InvalidArgumentException $e) {
                return new JsonResponse(['error' => $e->getMessage()], 422);
            }

            return new JsonResponse(['directive' => $directive->toArray()], 201);
        });
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        return $this->signed($request, $id, fn (): JsonResponse => $this->telemetry->directives()->remove($id)
            ? new JsonResponse(null, 204)
            : new JsonResponse(['error' => 'Not found.'], 404));
    }

    /**
     * @param  \Closure(): JsonResponse  $then
     */
    private function signed(Request $request, string $id, \Closure $then): JsonResponse
    {
        $secret = config('telemetry.directives.secret');
        $timestamp = $request->headers->get('X-Telemetry-Timestamp');
        $signature = $request->headers->get('X-Telemetry-Signature');

        if (! is_string($secret) || $secret === ''
            || ! is_string($timestamp) || ! ctype_digit($timestamp)
            || abs(time() - Cast::int($timestamp)) > self::MAX_SKEW_SECONDS
            || ! is_string($signature)
            || ! hash_equals(self::signature($secret, $timestamp, $request->getMethod(), $id, $request->getContent()), $signature)) {
            return new JsonResponse(['error' => 'Invalid signature.'], 401);
        }

        try {
            // Once per signature within the skew window. `add` is atomic
            // on every store that matters (redis, memcached, database).
            if (! $this->telemetry->directives()->claimOnce('sig:'.hash('sha256', $signature), self::MAX_SKEW_SECONDS * 2)) {
                return new JsonResponse(['error' => 'Signature already used.'], 401);
            }

            return $then();
        } catch (Throwable) {
            return new JsonResponse(['error' => 'Directive store unavailable.'], 503);
        }
    }

    /**
     * The signature a caller sends — public so a client in PHP (telemetry
     * UI, insights) and the tests compute it the one way the server checks.
     */
    public static function signature(string $secret, string $timestamp, string $method, string $id, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.strtoupper($method).'.'.$id.'.'.$body, $secret);
    }
}
