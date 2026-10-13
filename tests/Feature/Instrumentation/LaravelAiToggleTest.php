<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Tests\Feature\Instrumentation;

use Cbox\Telemetry\Instrumentation\LaravelAiInstrumentation;
use Cbox\Telemetry\Tests\TestCase;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StartingStep;
use PHPUnit\Framework\Attributes\Test;

/**
 * Listeners are registered while the application boots, so the switch has
 * to be off before it does — hence a case of its own.
 */
final class LaravelAiToggleTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('telemetry.instrument.ai', false);
    }

    #[Test]
    public function turning_the_instrumentation_off_registers_no_listeners(): void
    {
        $events = $this->app->make(Dispatcher::class);

        $this->assertFalse($events->hasListeners(PromptingAgent::class));
        $this->assertFalse($events->hasListeners(StartingStep::class));
        $this->assertFalse($this->app->resolved(LaravelAiInstrumentation::class));
    }
}
