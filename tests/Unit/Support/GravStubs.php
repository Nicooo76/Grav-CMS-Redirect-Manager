<?php

declare(strict_types=1);

/**
 * Loads the minimal stand-ins for Grav, the API plugin, PSR-7, Nyholm and Twig classes that the unit tests need.
 *
 * Grav is not in vendor/, so the classes of Grav/, Auto/ (listener, snapshotter) and Api/ can only be unit tested
 * against hand-written stand-ins. Every stub file declares its classes only when they do not exist yet, so a real
 * Grav (or PSR-7 package) always wins. The stubs implement just what the plugin calls, nothing more; the real
 * behavior is covered by the integration suite. See docs/TESTING.md.
 *
 * Stub files are loaded on demand by GravTestCase; production code and PHPStan never see them.
 */

foreach (glob(__DIR__ . '/Grav/*.php') ?: [] as $stub) {
    require_once $stub;
}
