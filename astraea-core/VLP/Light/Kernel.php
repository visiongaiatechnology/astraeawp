<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\VLP\Light;

use Astraea\VLP\Light\Admin\AdminPage;
use Astraea\VLP\Light\Dattrack\DattrackService;

final class Kernel {
    private static bool $booted=false;
    public static function boot(): void {
        if(self::$booted)return; self::$booted=true;
        Frontend::init(); DattrackService::init();
        if(is_admin()) AdminPage::init();
    }
    public static function isBooted(): bool { return self::$booted; }
}
