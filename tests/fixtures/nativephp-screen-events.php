<?php

declare(strict_types=1);

/*
 * Stand-ins for NativePHP's own screen lifecycle events.
 *
 * The real classes live in nativephp/mobile, which cannot be a dev
 * dependency here: it requires PHP ^8.4 while this package still supports
 * 8.3, so adding it would break the 8.3 CI cell at install time. They are
 * declared under the upstream namespace instead — the listener resolves
 * events by class name, so a `class_alias` would not match (get_class()
 * returns the original name, never the alias).
 *
 * Shape verified against NativePHP/mobile-air@main after #248 merged:
 * a public `component` class-string and a nullable `uri`.
 */

namespace Native\Mobile\Events\Screen;

if (! class_exists(ScreenMounted::class, false)) {
    class ScreenMounted
    {
        public function __construct(
            public string $component,
            public ?string $uri = null,
        ) {}
    }
}

if (! class_exists(ScreenResumed::class, false)) {
    class ScreenResumed
    {
        public function __construct(
            public string $component,
            public ?string $uri = null,
        ) {}
    }
}

if (! class_exists(ScreenUnmounted::class, false)) {
    class ScreenUnmounted
    {
        public function __construct(
            public string $component,
            public ?string $uri = null,
        ) {}
    }
}
