<?php
// STATUS: DIAMANT VGT SUPREME

declare(strict_types=1);

namespace Astraea\Mail\Security;

use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\ValidationException;

final class EndpointPolicy
{
    /** @var array<string,true> */
    private const FORBIDDEN_METADATA_IPS = [
        '169.254.169.254' => true,
        '169.254.170.2' => true,
        '100.100.100.200' => true,
        '192.0.0.192' => true,
    ];

    public static function normalizeHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '';
        }
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if (strlen($host) > 253 || preg_match('/[\x00-\x20\x7f\/\\@?#]/', $host) === 1) {
            throw new SecurityException('SMTP host validation failed due to forbidden path or control characters.');
        }
        $host = rtrim($host, '.');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return strtolower($host);
        }
        if (str_contains($host, ':')) {
            throw new ValidationException('SMTP-Host enthält einen ungültigen Port- oder URL-Anteil.');
        }
        if (preg_match('/[^\x20-\x7E]/', $host) === 1) {
            if (!function_exists('idn_to_ascii')) {
                throw new ValidationException('Internationalisierte SMTP-Hosts benötigen die INTL-Erweiterung.');
            }
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (!is_string($ascii) || $ascii === '') {
                throw new ValidationException('SMTP-Host konnte nicht in ASCII normalisiert werden.');
            }
            $host = $ascii;
        }
        if (preg_match('/\A(?=.{1,253}\z)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\z/D', $host) !== 1) {
            throw new ValidationException('SMTP-Host ist kein gültiger Hostname.');
        }
        return strtolower($host);
    }

    public static function normalizeOptionalDomain(string $domain): string
    {
        $domain = trim($domain);
        return $domain === '' ? '' : self::normalizeHost($domain);
    }

    public static function assertAllowed(string $host, bool $allowPrivate): void
    {
        if ($host === '') {
            return;
        }
        $addresses = self::resolve($host);
        if ($addresses === []) {
            throw new ValidationException('SMTP-Host konnte nicht per DNS aufgelöst werden.');
        }
        foreach ($addresses as $address) {
            if (isset(self::FORBIDDEN_METADATA_IPS[$address])) {
                throw new SecurityException('SMTP target resolves to a forbidden metadata service address.');
            }
            $public = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
            if (!$public && !$allowPrivate) {
                throw new SecurityException('SMTP target resolves to a private, loopback, link-local or reserved address without explicit private-relay authorization.');
            }
        }
    }

    /** @return list<string> */
    public static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $addresses = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ip']) && is_string($record['ip']) && filter_var($record['ip'], FILTER_VALIDATE_IP)) {
                        $addresses[] = $record['ip'];
                    }
                    if (isset($record['ipv6']) && is_string($record['ipv6']) && filter_var($record['ipv6'], FILTER_VALIDATE_IP)) {
                        $addresses[] = $record['ipv6'];
                    }
                }
            }
        }
        if ($addresses === []) {
            $ipv4 = @gethostbynamel($host);
            if (is_array($ipv4)) {
                foreach ($ipv4 as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        $addresses[] = $ip;
                    }
                }
            }
        }
        return array_values(array_unique($addresses));
    }


    public static function assertTransportAllowed(string $host, bool $allowPrivate, string $encryption): void
    {
        self::assertAllowed($host, $allowPrivate);
        if ($encryption !== 'none') {
            return;
        }
        if (!$allowPrivate || !self::isExclusivelyPrivateOrLoopbackHost($host)) {
            throw new SecurityException('Plaintext SMTP is restricted to explicitly authorized private or loopback relays.');
        }
    }

    public static function isExclusivelyPrivateOrLoopbackHost(string $host): bool
    {
        $addresses = self::resolve($host);
        if ($addresses === []) {
            return false;
        }
        foreach ($addresses as $address) {
            if (isset(self::FORBIDDEN_METADATA_IPS[$address])) {
                return false;
            }
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                return false;
            }
        }
        return true;
    }

    public static function isPrivateOrLoopbackHost(string $host): bool
    {
        try {
            foreach (self::resolve($host) as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }
        return false;
    }
}
