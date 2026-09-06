<?php
// STATUS: DIAMANT VGT SUPREME

declare(strict_types=1);

namespace Astraea\Mail\Transport;

use Astraea\Exceptions\SecurityException;
use Astraea\Mail\SmtpConfig;
use PHPMailer\PHPMailer\OAuthTokenProvider;

final class OAuthProvider implements OAuthTokenProvider
{
    public function __construct(private readonly SmtpConfig $config) {}

    public function getOauth64()
    {
        $username = $this->config->effectiveUsername();
        if ($username === '' || preg_match('/[\x00\x01\r\n]/', $username) === 1) {
            throw new SecurityException('XOAUTH2 username failed structural validation.');
        }
        $token = OAuthTokenService::accessToken($this->config);
        return base64_encode('user=' . $username . "\x01auth=Bearer " . $token . "\x01\x01");
    }
}
