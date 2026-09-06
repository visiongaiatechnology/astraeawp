<?php
// STATUS: DIAMANT VGT SUPREME

declare(strict_types=1);

namespace Astraea\Mail\Transport;

use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\StorageException;
use Astraea\Exceptions\ValidationException;
use Astraea\Mail\Security\OAuthEndpointPolicy;
use Astraea\Mail\Settings;
use Astraea\Mail\SmtpConfig;
use Astraea\Mail\Store\EncryptedRecordStore;
use JsonException;

final class OAuthTokenService
{
    private const MIN_TOKEN_LENGTH = 8;
    private const MAX_TOKEN_LENGTH = 16384;

    private function __construct() {}

    public static function accessToken(SmtpConfig $config): string
    {
        if ($config->authType !== 'xoauth2') {
            throw new ValidationException('OAuth token service requires XOAUTH2 configuration.');
        }

        $external = Settings::externalOAuthAccessToken();
        if ($external !== '') {
            return self::validateToken($external);
        }

        OAuthEndpointPolicy::assertAllowed($config->oauthTokenUrl);
        $fingerprint = self::configFingerprint($config);
        $cached = EncryptedRecordStore::loadObject(Settings::OAUTH_CACHE_OPTION, Settings::OAUTH_CACHE_AAD);
        if (
            isset($cached['fingerprint'], $cached['access_token'], $cached['expires_at'])
            && is_string($cached['fingerprint'])
            && is_string($cached['access_token'])
            && is_numeric($cached['expires_at'])
            && hash_equals($fingerprint, $cached['fingerprint'])
            && (int)$cached['expires_at'] > time() + 60
        ) {
            return self::validateToken($cached['access_token']);
        }

        if (!function_exists('wp_remote_post') || !function_exists('wp_remote_retrieve_response_code') || !function_exists('wp_remote_retrieve_body')) {
            throw new StorageException('WordPress HTTP API is unavailable for OAuth token refresh.');
        }

        $body = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $config->effectiveOAuthRefreshToken(),
            'client_id' => $config->oauthClientId,
        ];
        $clientSecret = $config->effectiveOAuthClientSecret();
        if ($clientSecret !== '') {
            $body['client_secret'] = $clientSecret;
        }
        if ($config->oauthScope !== '') {
            $body['scope'] = $config->oauthScope;
        }

        $response = wp_remote_post($config->oauthTokenUrl, [
            'timeout' => min(15, max(5, $config->timeout)),
            'redirection' => 0,
            'sslverify' => true,
            'reject_unsafe_urls' => true,
            'headers' => [
                'Accept' => 'application/json',
                'Cache-Control' => 'no-store',
            ],
            'body' => $body,
            'cookies' => [],
            'limit_response_size' => 65536,
            'user-agent' => 'AstraeaOS-WP-Mail/' . (defined('ASTRAEA_VERSION') ? ASTRAEA_VERSION : 'unknown'),
        ]);

        if (is_wp_error($response)) {
            throw new SecurityException('OAuth token refresh failed without exposing remote response details.');
        }
        $status = (int)wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            throw new SecurityException('OAuth token endpoint rejected refresh request.');
        }
        $raw = wp_remote_retrieve_body($response);
        if (!is_string($raw) || $raw === '' || strlen($raw) > 65536) {
            throw new SecurityException('OAuth token endpoint returned an invalid response size.');
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SecurityException('OAuth token endpoint returned malformed JSON.', 0, $e);
        }
        if (!is_array($decoded) || !isset($decoded['access_token']) || !is_string($decoded['access_token'])) {
            throw new SecurityException('OAuth token endpoint response did not contain an access token.');
        }
        $token = self::validateToken($decoded['access_token']);
        $expiresIn = isset($decoded['expires_in']) && is_numeric($decoded['expires_in']) ? (int)$decoded['expires_in'] : 300;
        $expiresIn = max(120, min(86400, $expiresIn));
        $expiresAt = time() + $expiresIn;

        EncryptedRecordStore::saveObject(Settings::OAUTH_CACHE_OPTION, Settings::OAUTH_CACHE_AAD, [
            'schema' => 1,
            'fingerprint' => $fingerprint,
            'access_token' => $token,
            'expires_at' => $expiresAt,
        ]);

        return $token;
    }

    public static function clearCache(): void
    {
        delete_option(Settings::OAUTH_CACHE_OPTION);
    }

    private static function configFingerprint(SmtpConfig $config): string
    {
        $material = implode("\0", [
            $config->oauthTokenUrl,
            $config->oauthClientId,
            $config->effectiveUsername(),
            $config->oauthScope,
            hash('sha256', $config->effectiveOAuthRefreshToken()),
            hash('sha256', $config->effectiveOAuthClientSecret()),
        ]);
        return hash('sha256', $material);
    }

    private static function validateToken(#[\SensitiveParameter] string $token): string
    {
        $len = strlen($token);
        if ($len < self::MIN_TOKEN_LENGTH || $len > self::MAX_TOKEN_LENGTH || preg_match('/[\x00\x01\r\n]/', $token) === 1) {
            throw new SecurityException('OAuth access token failed structural validation.');
        }
        return $token;
    }
}
