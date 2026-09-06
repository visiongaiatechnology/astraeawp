<?php
// STATUS: DIAMANT VGT SUPREME

declare(strict_types=1);

namespace Astraea\Mail;

use Astraea\Mail\Admin\AdminPage;
use Astraea\Mail\Transport\TransportManager;

final class Kernel
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        TransportManager::init();
        if (is_admin()) {
            AdminPage::init();
        }
    }

    public static function isBooted(): bool
    {
        return self::$booted;
    }
}
