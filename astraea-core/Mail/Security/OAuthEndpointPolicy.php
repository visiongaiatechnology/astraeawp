<?php
// STATUS: DIAMANT VGT SUPREME

declare(strict_types=1);

namespace Astraea\Mail\Security;

use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\ValidationException;

final class OAuthEndpointPolicy
{
    private function __construct() {}

    public static function normalizeHttpsUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new SecurityException('OAuth token endpoint validation failed due to control characters or excessive length.');
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new ValidationException('OAuth Token Endpoint ist ungültig.');
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            throw new SecurityException('OAuth token endpoint must use HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new SecurityException('OAuth token endpoint contains forbidden userinfo or fragment data.');
        }
        if (isset($parts['port']) && ((int)$parts['port'] < 1 || (int)$parts['port'] > 65535)) {
            throw new ValidationException('OAuth Token Endpoint enthält einen ungültigen Port.');
        }

        $host = EndpointPolicy::normalizeHost((string)($parts['host'] ?? ''));
        if ($host === '') {
            throw new ValidationException('OAuth Token Endpoint benötigt einen Hostnamen.');
        }
        $path = isset($parts['path']) && is_string($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
        if (!str_starts_with($path, '/') || str_contains($path, '\\')) {
            throw new SecurityException('OAuth token endpoint path validation failed.');
        }
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $query = isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return 'https://' . $host . $port . $path . $query;
    }

    public static function assertAllowed(string $url): void
    {
        $url = self::normalizeHttpsUrl($url);
        if ($url === '') {
            throw new ValidationException('OAuth Token Endpoint fehlt.');
        }
        $parts = parse_url($url);
        $host = is_array($parts) && isset($parts['host']) && is_string($parts['host']) ? $parts['host'] : '';
        $host = EndpointPolicy::normalizeHost($host);
        EndpointPolicy::assertAllowed($host, false);
    }
}
