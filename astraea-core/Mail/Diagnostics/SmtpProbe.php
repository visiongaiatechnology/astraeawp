<?php
// STATUS: DIAMANT VGT SUPREME

declare(strict_types=1);

namespace Astraea\Mail\Diagnostics;

use Astraea\Exceptions\SecurityException;
use Astraea\Mail\Security\EndpointPolicy;
use Astraea\Mail\Settings;
use Astraea\Mail\SmtpConfig;
use Astraea\Mail\Store\EncryptedRecordStore;
use Astraea\Mail\Transport\MailRuntime;
use Astraea\Mail\Transport\OAuthProvider;
use Astraea\Mail\Transport\TlsPolicy;
use PHPMailer\PHPMailer\SMTP;

final class SmtpProbe
{
    /** @return array<string,mixed> */
    public static function run(SmtpConfig $config): array
    {
        MailRuntime::ensureLoaded();
        $config->assertOperational(true);
        EndpointPolicy::assertTransportAllowed($config->host, $config->allowPrivateTarget, $config->encryption);

        $smtp = new InspectableSMTP();
        $smtp->do_debug = SMTP::DEBUG_OFF;
        $options = TlsPolicy::streamOptions($config->host);
        $host = $config->encryption === 'smtps' ? 'ssl://' . $config->host : $config->host;
        $hello = self::helloName();
        $connected = false;

        try {
            if (!$smtp->connect($host, $config->port, $config->timeout, $options)) {
                throw new \RuntimeException('connect_failed');
            }
            $connected = true;
            if (!$smtp->hello($hello)) {
                throw new \RuntimeException('ehlo_failed');
            }
            if ($config->encryption === 'starttls') {
                if (!$smtp->getServerExt('STARTTLS')) {
                    throw new SecurityException('STARTTLS was required but not advertised by the SMTP server.');
                }
                if (!$smtp->startTLS() || !$smtp->hello($hello)) {
                    throw new SecurityException('STARTTLS negotiation failed.');
                }
            }
            if ($config->auth) {
                $authType = $config->authType === 'auto' ? null : strtoupper($config->authType);
                $password = $config->authType === 'xoauth2' ? '' : $config->effectivePassword();
                $oauth = $config->authType === 'xoauth2' ? new OAuthProvider($config) : null;
                if (!$smtp->authenticate($config->effectiveUsername(), $password, $authType, $oauth)) {
                    throw new SecurityException('SMTP authentication failed.');
                }
            }

            $extensionsRaw = $smtp->getServerExtList();
            $extensions = is_array($extensionsRaw) ? $extensionsRaw : [];
            $meta = $smtp->transportMeta();
            $cert = is_array($meta['certificate'] ?? null) ? $meta['certificate'] : [];
            $crypto = is_array($meta['crypto'] ?? null) ? $meta['crypto'] : [];
            $warnings = [];
            $validTo = isset($cert['validTo_time_t']) && is_numeric($cert['validTo_time_t']) ? (int)$cert['validTo_time_t'] : 0;
            if ($validTo > 0 && $validTo < time() + 14 * DAY_IN_SECONDS) {
                $warnings[] = 'Das SMTP-Zertifikat läuft in weniger als 14 Tagen ab.';
            }
            if ($config->encryption === 'none') {
                $warnings[] = 'Lokaler/private SMTP-Relay ohne Transportverschlüsselung ist explizit freigegeben.';
            }

            $result = [
                'success' => true,
                'timestamp' => time(),
                'datetime' => gmdate('c'),
                'host' => $config->host,
                'port' => $config->port,
                'encryption' => $config->encryption,
                'auth' => $config->auth,
                'auth_mechanisms' => self::safeAuthMechanisms($extensions),
                'tls_protocol' => self::cleanToken((string)($crypto['protocol'] ?? '')),
                'tls_cipher' => self::cleanToken((string)($crypto['cipher_name'] ?? '')),
                'certificate_subject' => self::certificateName($cert['subject'] ?? []),
                'certificate_issuer' => self::certificateName($cert['issuer'] ?? []),
                'certificate_expires' => $validTo > 0 ? gmdate('c', $validTo) : '',
                'warnings' => $warnings,
            ];
            EncryptedRecordStore::saveObject(Settings::PROBE_OPTION, Settings::PROBE_AAD, $result);
            return $result;
        } finally {
            if ($connected) {
                try { $smtp->quit(true); } catch (\Throwable) {}
                $smtp->close();
            }
        }
    }

    /** @return array<string,mixed> */
    public static function last(): array
    {
        return EncryptedRecordStore::loadObject(Settings::PROBE_OPTION, Settings::PROBE_AAD);
    }

    /** @param array<string,mixed> $extensions @return list<string> */
    private static function safeAuthMechanisms(array $extensions): array
    {
        $auth = $extensions['AUTH'] ?? [];
        if (!is_array($auth)) {
            return [];
        }
        $safe = [];
        foreach ($auth as $method) {
            if (is_string($method) && preg_match('/\A[A-Z0-9_-]{1,32}\z/D', strtoupper($method)) === 1) {
                $safe[] = strtoupper($method);
            }
        }
        return array_values(array_unique($safe));
    }

    /** @param mixed $value */
    private static function certificateName(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }
        foreach (['CN', 'O'] as $key) {
            if (isset($value[$key]) && is_string($value[$key])) {
                return substr(preg_replace('/[\x00-\x1F\x7F]/', '', $value[$key]) ?? '', 0, 180);
            }
        }
        return '';
    }

    private static function cleanToken(string $value): string
    {
        return substr(preg_replace('/[^A-Za-z0-9_.:+\/-]/', '', $value) ?? '', 0, 128);
    }

    private static function helloName(): string
    {
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return preg_replace('/[^A-Za-z0-9.-]/', '', $host) ?: 'localhost.localdomain';
        }
        return 'localhost.localdomain';
    }
}
