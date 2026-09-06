<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault;

final class Autoloader
{
    private const PREFIX = 'Astraea\\Vault\\';
    private static string $baseDir = '';

    public static function register(string $baseDir): void
    {
        self::$baseDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;
        spl_autoload_register([self::class, 'load'], true, true);
    }

    public static function load(string $class): void
    {
        if (!str_starts_with($class, self::PREFIX)) {
            return;
        }

        $relative = substr($class, strlen(self::PREFIX));
        if ($relative === false || $relative === '' || preg_match('/[^A-Za-z0-9_\\\\]/', $relative)) {
            return;
        }

        $path = self::$baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
}
