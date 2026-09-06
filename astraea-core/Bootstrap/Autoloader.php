<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Bootstrap;

/**
 * AstraeaOS Core PSR-4 Autoloader.
 *
 * Maps namespace `Astraea\` directly to `/astraea-core/`.
 * Lightweight, zero-dependency, and deterministic.
 *
 * @package Astraea\Bootstrap
 */
final class Autoloader {

    private static bool $registered = false;
    private static string $baseDir = '';

    /**
     * Register the autoloader.
     *
     * @param string|null $baseDir Base directory of astraea-core.
     */
    public static function register(?string $baseDir = null): void {
        if (self::$registered) {
            return;
        }

        self::$baseDir = rtrim($baseDir ?? dirname(__DIR__), '/\\') . DIRECTORY_SEPARATOR;

        spl_autoload_register(function (string $class): void {
            $prefix = 'Astraea\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relativeClass = substr($class, strlen($prefix));
            $file = self::$baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

            if (is_file($file)) {
                require_once $file;
            }
        }, true, true);

        self::$registered = true;
    }

    /**
     * Check if autoloader is active.
     */
    public static function isRegistered(): bool {
        return self::$registered;
    }
}
