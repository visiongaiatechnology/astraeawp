<?php
// STATUS: DIAMANT VGT SUPREME

declare(strict_types=1);

namespace Astraea\Mail\Transport;

use Astraea\Exceptions\StorageException;

final class MailRuntime
{
    public static function ensureLoaded(): void
    {
        if (class_exists('\PHPMailer\PHPMailer\PHPMailer') && class_exists('\PHPMailer\PHPMailer\SMTP') && interface_exists('\PHPMailer\PHPMailer\OAuthTokenProvider')) {
            return;
        }
        if (!defined('ABSPATH') || !defined('WPINC')) {
            throw new StorageException('WordPress mail runtime paths are unavailable.');
        }
        $base = rtrim(ABSPATH, '/\\') . DIRECTORY_SEPARATOR . WPINC . DIRECTORY_SEPARATOR . 'PHPMailer' . DIRECTORY_SEPARATOR;
        foreach (['Exception.php', 'OAuthTokenProvider.php', 'SMTP.php', 'PHPMailer.php'] as $file) {
            $path = $base . $file;
            if (!is_file($path)) {
                throw new StorageException('Bundled WordPress mail runtime is incomplete.');
            }
            require_once $path;
        }
    }
}
