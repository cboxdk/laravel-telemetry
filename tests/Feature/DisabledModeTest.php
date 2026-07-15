<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Tests\Feature;

use Cbox\Telemetry\Tests\DisabledTestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

final class DisabledModeTest extends DisabledTestCase
{
    #[Test]
    public function http_with_traceparent_stays_callable_when_telemetry_is_disabled(): void
    {
        Http::fake();

        Http::withTraceparent()->post('https://example.com/api', ['x' => 1]);

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('traceparent'));
    }

    #[Test]
    public function blade_directives_render_empty_output_when_telemetry_is_disabled(): void
    {
        config()->set('telemetry.ingest.spans.enabled', true);

        $this->assertSame('', Blade::render('@telemetryTraceparent'));
        $this->assertSame('', Blade::render('@telemetryBrowser'));
    }
}
