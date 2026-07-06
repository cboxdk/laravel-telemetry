<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\ResourceDetector;

afterEach(function () {
    ResourceDetector::flush();
    foreach ([
        'K8S_POD_NAME', 'POD_NAME', 'K8S_POD_NAMESPACE', 'POD_NAMESPACE', 'K8S_NODE_NAME', 'AWS_REGION', 'HOSTNAME', 'OTEL_RESOURCE_ATTRIBUTES',
        'AWS_LAMBDA_FUNCTION_NAME', 'AWS_LAMBDA_FUNCTION_VERSION', 'AWS_LAMBDA_LOG_STREAM_NAME', 'AWS_LAMBDA_FUNCTION_MEMORY_SIZE', 'VAPOR_SSM_PATH',
    ] as $k) {
        putenv($k);
    }
});

it('maps well-known downward-API env vars to OTel resource attributes', function () {
    putenv('K8S_POD_NAME=web-7d9f-abc');
    putenv('POD_NAMESPACE=production');
    putenv('K8S_NODE_NAME=node-3');
    putenv('AWS_REGION=eu-west-1');
    ResourceDetector::flush();

    $attrs = ResourceDetector::detect();

    expect($attrs['k8s.pod.name'])->toBe('web-7d9f-abc')
        ->and($attrs['k8s.namespace.name'])->toBe('production')
        ->and($attrs['k8s.node.name'])->toBe('node-3')
        ->and($attrs['cloud.region'])->toBe('eu-west-1');
});

it('falls back to HOSTNAME for the pod name only when a namespace is present', function () {
    putenv('HOSTNAME=web-7d9f-xyz');
    putenv('K8S_POD_NAMESPACE=staging');
    ResourceDetector::flush();

    expect(ResourceDetector::detect()['k8s.pod.name'])->toBe('web-7d9f-xyz');

    // Without a namespace, HOSTNAME is not assumed to be a pod name.
    ResourceDetector::flush();
    putenv('K8S_POD_NAMESPACE');
    ResourceDetector::flush();

    expect(ResourceDetector::detect())->not->toHaveKey('k8s.pod.name');
});

it('parses OTEL_RESOURCE_ATTRIBUTES and lets it override the env conventions', function () {
    putenv('K8S_POD_NAME=from-downward-api');
    putenv('OTEL_RESOURCE_ATTRIBUTES=k8s.pod.name=explicit,service.instance.id=abc123,cloud.region=us-east-2');
    ResourceDetector::flush();

    $attrs = ResourceDetector::detect();

    expect($attrs['k8s.pod.name'])->toBe('explicit')
        ->and($attrs['service.instance.id'])->toBe('abc123')
        ->and($attrs['cloud.region'])->toBe('us-east-2');
});

it('percent-decodes OTEL_RESOURCE_ATTRIBUTES values', function () {
    putenv('OTEL_RESOURCE_ATTRIBUTES=deployment.note=hot%20fix%2C%20urgent');
    ResourceDetector::flush();

    expect(ResourceDetector::detect()['deployment.note'])->toBe('hot fix, urgent');
});

it('returns an empty map with nothing to detect', function () {
    ResourceDetector::flush();

    expect(ResourceDetector::detect())->toBeArray();
});

it('maps AWS Lambda runtime env vars to faas.* and cloud.platform', function () {
    putenv('AWS_LAMBDA_FUNCTION_NAME=my-app-production-http');
    putenv('AWS_LAMBDA_FUNCTION_VERSION=$LATEST');
    putenv('AWS_LAMBDA_LOG_STREAM_NAME=2024/01/01/[1]abc123');
    putenv('AWS_LAMBDA_FUNCTION_MEMORY_SIZE=512');
    ResourceDetector::flush();

    $attrs = ResourceDetector::detect();

    expect($attrs['cloud.provider'])->toBe('aws')
        ->and($attrs['cloud.platform'])->toBe('aws_lambda')
        ->and($attrs['faas.name'])->toBe('my-app-production-http')
        ->and($attrs['faas.version'])->toBe('$LATEST')
        ->and($attrs['faas.instance'])->toBe('2024/01/01/[1]abc123')
        ->and($attrs['faas.max_memory'])->toBe((string) (512 * 1024 * 1024));
});

it('flags Vapor specifically via VAPOR_SSM_PATH', function () {
    putenv('AWS_LAMBDA_FUNCTION_NAME=my-app-production-http');
    putenv('VAPOR_SSM_PATH=/vapor/my-app/production');
    ResourceDetector::flush();

    expect(ResourceDetector::detect()['vapor.detected'])->toBe('true');
});

it('never claims Lambda without AWS_LAMBDA_FUNCTION_NAME', function () {
    putenv('VAPOR_SSM_PATH=/vapor/my-app/production');
    ResourceDetector::flush();

    expect(ResourceDetector::detect())
        ->not->toHaveKey('faas.name')
        ->not->toHaveKey('vapor.detected');
});

it('marks only the first detect() call in the process as a cold start', function () {
    putenv('AWS_LAMBDA_FUNCTION_NAME=my-app-production-http');
    ResourceDetector::flush();

    expect(ResourceDetector::detect()['faas.coldstart'])->toBe('true')
        ->and(ResourceDetector::detect()['faas.coldstart'])->toBe('false');
});

it('lets an explicit cloud.provider env var win over the aws inference', function () {
    putenv('AWS_LAMBDA_FUNCTION_NAME=my-app-production-http');
    putenv('OTEL_RESOURCE_ATTRIBUTES=cloud.provider=custom');
    ResourceDetector::flush();

    expect(ResourceDetector::detect()['cloud.provider'])->toBe('custom');
});
