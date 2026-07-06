<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Support;

use Cbox\SystemMetrics\DTO\Environment\ContainerType;
use Cbox\SystemMetrics\SystemMetrics;

/**
 * Detects deployment-environment resource attributes (OTel semconv) so a
 * trace carries WHERE it ran — the single most-wanted filter dimension
 * in a containerized fleet, where host.name is just a random pod hash.
 *
 * Four sources, lowest precedence first:
 * 1. Container facts from cboxdk/system-metrics (cgroup-aware): the
 *    container runtime and id, and whether we're on Kubernetes.
 * 2. Well-known downward-API / cloud env vars (POD_NAME, K8S_*, AWS_REGION
 *    …) mapped to `k8s.` and `cloud.` attributes — convenience for the
 *    common injection patterns.
 * 3. AWS Lambda's own runtime env vars (always present in that runtime,
 *    Vapor included — it runs Bref's PHP-FPM Lambda layer under the
 *    hood) mapped to `faas.*` + `cloud.platform` (OTel semconv).
 * 4. OTEL_RESOURCE_ATTRIBUTES (the OpenTelemetry standard: comma-separated
 *    key=value) — the operator's explicit word, overrides the above.
 *
 * Everything here is best-effort and memoized: it never throws, and the
 * package's own config keys (service.name, …) always win over detection.
 */
final class ResourceDetector
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /**
     * Whether this is the first detect() call in the process — the FaaS
     * coldstart signal. Deliberately NOT part of $cache: Lambda/Vapor's
     * PHP-FPM worker is reused across invocations in the same execution
     * environment, so this must be re-evaluated (and flip to false)
     * every call while the rest of the resource stays memoized.
     */
    private static bool $coldStart = true;

    /**
     * @return array<string, string>
     */
    public static function detect(): array
    {
        self::$cache ??= FailSafe::guard(static function (): array {
            $attributes = [];

            self::mergeContainer($attributes);
            self::mergeEnvConventions($attributes);
            self::mergeFaas($attributes);
            self::mergeOtelResourceAttributes($attributes);

            return array_filter($attributes, static fn (string $v): bool => $v !== '');
        }) ?? [];

        $attributes = self::$cache;

        if (isset($attributes['faas.name'])) {
            $attributes['faas.coldstart'] = self::$coldStart ? 'true' : 'false';
            self::$coldStart = false;
        }

        return $attributes;
    }

    /**
     * @internal for tests
     */
    public static function flush(): void
    {
        self::$cache = null;
        self::$coldStart = true;
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private static function mergeContainer(array &$attributes): void
    {
        if (! class_exists(SystemMetrics::class)) {
            return;
        }

        $containerization = SystemMetrics::environment()->getValueOr(null)?->containerization;

        if ($containerization === null || ! $containerization->insideContainer) {
            return;
        }

        if (is_string($containerization->rawIdentifier) && $containerization->rawIdentifier !== '') {
            $attributes['container.id'] = $containerization->rawIdentifier;
        }

        if (is_string($containerization->runtime) && $containerization->runtime !== '') {
            $attributes['container.runtime'] = $containerization->runtime;
        }

        if ($containerization->type === ContainerType::Kubernetes) {
            // Marks the platform; the specific pod/namespace come from env.
            $attributes['k8s.cluster.detected'] = 'true';
        }
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private static function mergeEnvConventions(array &$attributes): void
    {
        // OTel resource key => the env var names commonly used to inject it
        // (first non-empty wins). No single standard exists for the names,
        // so we cover the widespread downward-API conventions.
        $map = [
            'k8s.pod.name' => ['K8S_POD_NAME', 'POD_NAME'],
            'k8s.pod.uid' => ['K8S_POD_UID', 'POD_UID'],
            'k8s.namespace.name' => ['K8S_POD_NAMESPACE', 'K8S_NAMESPACE', 'POD_NAMESPACE'],
            'k8s.node.name' => ['K8S_NODE_NAME', 'NODE_NAME'],
            'k8s.deployment.name' => ['K8S_DEPLOYMENT_NAME'],
            'k8s.container.name' => ['K8S_CONTAINER_NAME'],
            'cloud.region' => ['CLOUD_REGION', 'AWS_REGION', 'GOOGLE_CLOUD_REGION', 'FLY_REGION'],
            'cloud.availability_zone' => ['CLOUD_AVAILABILITY_ZONE', 'AWS_AVAILABILITY_ZONE'],
            'cloud.provider' => ['CLOUD_PROVIDER'],
        ];

        foreach ($map as $attribute => $envNames) {
            foreach ($envNames as $envName) {
                $value = getenv($envName);

                if (is_string($value) && $value !== '') {
                    $attributes[$attribute] = $value;

                    break;
                }
            }
        }

        // In Kubernetes the pod's hostname IS the pod name by default —
        // only used as a fallback so the explicit env vars win.
        if (! isset($attributes['k8s.pod.name']) && isset($attributes['k8s.namespace.name'])) {
            $hostname = getenv('HOSTNAME');

            if (is_string($hostname) && $hostname !== '') {
                $attributes['k8s.pod.name'] = $hostname;
            }
        }
    }

    /**
     * AWS Lambda always sets these in the execution environment — Vapor
     * included, since it deploys on Bref's PHP-FPM Lambda layer. Absence
     * of AWS_LAMBDA_FUNCTION_NAME means "not on Lambda"; everything else
     * here is conditional on that.
     *
     * @param  array<string, string>  $attributes
     */
    private static function mergeFaas(array &$attributes): void
    {
        $functionName = getenv('AWS_LAMBDA_FUNCTION_NAME');

        if (! is_string($functionName) || $functionName === '') {
            return;
        }

        $attributes['cloud.provider'] ??= 'aws';
        $attributes['cloud.platform'] = 'aws_lambda';
        $attributes['faas.name'] = $functionName;

        $version = getenv('AWS_LAMBDA_FUNCTION_VERSION');

        if (is_string($version) && $version !== '') {
            $attributes['faas.version'] = $version;
        }

        // The log stream name embeds a unique id per execution
        // environment — OTel semconv's recommended faas.instance value
        // when the platform has no more specific instance identifier.
        $logStream = getenv('AWS_LAMBDA_LOG_STREAM_NAME');

        if (is_string($logStream) && $logStream !== '') {
            $attributes['faas.instance'] = $logStream;
        }

        $memoryMb = getenv('AWS_LAMBDA_FUNCTION_MEMORY_SIZE');

        if (is_string($memoryMb) && is_numeric($memoryMb)) {
            // faas.max_memory is bytes per OTel semconv; Lambda reports MB.
            $attributes['faas.max_memory'] = (string) ((int) $memoryMb * 1024 * 1024);
        }

        $vaporPath = getenv('VAPOR_SSM_PATH');

        if (is_string($vaporPath) && $vaporPath !== '') {
            $attributes['vapor.detected'] = 'true';
        }
    }

    /**
     * The OpenTelemetry standard: OTEL_RESOURCE_ATTRIBUTES="k=v,k2=v2".
     * Operator-explicit, so it overrides the detected conventions.
     *
     * @param  array<string, string>  $attributes
     */
    private static function mergeOtelResourceAttributes(array &$attributes): void
    {
        $raw = getenv('OTEL_RESOURCE_ATTRIBUTES');

        if (! is_string($raw) || $raw === '') {
            return;
        }

        foreach (explode(',', $raw) as $pair) {
            if (! str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $pair, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key !== '') {
                // Percent-decoding per the OTel spec (values may be encoded).
                $attributes[$key] = rawurldecode($value);
            }
        }
    }
}
