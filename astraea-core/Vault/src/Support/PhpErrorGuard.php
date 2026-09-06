<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Support;

final class PhpErrorGuard
{
    public static function run(callable $operation): mixed
    {
        $previousDisplay = ini_get('display_errors');
        $previousLevel = error_reporting();
        ini_set('display_errors', '0');
        error_reporting(E_ALL);
        set_error_handler(static function(int $sev, string $msg, string $file, int $line): bool {
            if (!(error_reporting() & $sev)) return false;
            throw new \ErrorException($msg, 0, $sev, $file, $line);
        });
        try {
            return $operation();
        } finally {
            restore_error_handler();
            error_reporting($previousLevel);
            if ($previousDisplay !== false) {
                ini_set('display_errors', (string)$previousDisplay);
            }
        }
    }
}
