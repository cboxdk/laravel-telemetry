<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Instrumentation;

use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Authentication lifecycle counters — auth.events{event, guard}. The
 * security signals live here: a spike in `failed` is a credential
 * attack, `lockout` is the throttle catching it.
 *
 * Deliberately no user identifiers on the metric (bounded labels only);
 * the request trace already carries enduser.* for the who.
 */
final class AuthInstrumentation
{
    public function __construct(private readonly Container $container) {}

    public function register(Dispatcher $events): void
    {
        $events->listen(Login::class, fn (Login $event) => $this->count('login', $event->guard));
        $events->listen(Logout::class, fn (Logout $event) => $this->count('logout', $event->guard));
        $events->listen(Failed::class, fn (Failed $event) => $this->count('failed', $event->guard));
        $events->listen(Lockout::class, fn () => $this->count('lockout', null));
        $events->listen(PasswordReset::class, fn () => $this->count('password_reset', null));
        $events->listen(Registered::class, fn () => $this->count('registered', null));
        $events->listen(Verified::class, fn () => $this->count('verified', null));
        $events->listen(OtherDeviceLogout::class, fn (OtherDeviceLogout $event) => $this->count('other_device_logout', $event->guard));
    }

    private function count(string $event, ?string $guard): void
    {
        FailSafe::guard(fn () => $this->container->make(TelemetryManager::class)
            ->counter('auth.events', 'Authentication lifecycle events')
            ->inc(1, ['event' => $event, 'guard' => $guard ?? 'default']));
    }
}
