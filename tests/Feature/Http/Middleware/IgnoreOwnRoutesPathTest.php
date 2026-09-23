<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Tests\Feature\Http\Middleware;

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The self-exclusion is registered from the path each route was actually
 * configured with — a hardcoded 'telemetry/metrics' would stop excluding
 * anything the moment an operator moved the endpoint, which is exactly
 * when they would not notice.
 */
final class IgnoreOwnRoutesPathTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('telemetry.prometheus.endpoints.default.path', 'internal/metrics');
    }

    #[Test]
    public function it_follows_the_path_the_endpoint_was_configured_with(): void
    {
        $this->assertTrue(Telemetry::ignoresPath('internal/metrics'));
        $this->assertFalse(Telemetry::ignoresPath('telemetry/metrics'));

        $this->get('/internal/metrics')->assertOk()->assertHeaderMissing('X-Trace-Id');
    }
}
