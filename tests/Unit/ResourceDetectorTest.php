<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\ResourceDetector;

afterEach(function () {
    ResourceDetector::flush();
    foreach (['K8S_POD_NAME', 'POD_NAME', 'K8S_POD_NAMESPACE', 'POD_NAMESPACE', 'K8S_NODE_NAME', 'AWS_REGION', 'HOSTNAME', 'OTEL_RESOURCE_ATTRIBUTES'] as $k) {
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
