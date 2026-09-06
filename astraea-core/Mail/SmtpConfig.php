<?php
// STATUS: DIAMANT VGT SUPREME

declare(strict_types=1);

namespace Astraea\Mail;

use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\ValidationException;
use Astraea\Mail\Security\EndpointPolicy;
use Astraea\Mail\Security\OAuthEndpointPolicy;

final readonly class SmtpConfig
{
    public function __construct(
        public bool $enabled,
        public string $provider,
        public string $host,
        public int $port,
        public string $encryption,
        public bool $auth,
        public string $authType,
        public string $username,
        #[\SensitiveParameter] public string $password,
        public string $oauthTokenUrl,
        public string $oauthClientId,
        #[\SensitiveParameter] public string $oauthClientSecret,
        #[\SensitiveParameter] public string $oauthRefreshToken,
        public string $oauthScope,
        public string $fromEmail,
        public string $fromName,
        public bool $forceFromEmail,
        public bool $forceFromName,
        public bool $returnPath,
        public int $timeout,
        public bool $allowPrivateTarget,
        public bool $dkimEnabled,
        public string $dkimDomain,
        public string $dkimSelector,
        public string $dkimIdentity,
        #[\SensitiveParameter] public string $dkimPrivateKey,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $provider = isset($data['provider']) && is_string($data['provider']) ? sanitize_key($data['provider']) : 'custom';
        if (!ProviderRegistry::exists($provider)) {
            throw new ValidationException('Unbekannter SMTP-Anbieter.');
        }
        $preset = ProviderRegistry::get($provider);

        $host = EndpointPolicy::normalizeHost((string)($data['host'] ?? ''));
        $port = filter_var($data['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) {
            throw new ValidationException('SMTP-Port liegt außerhalb des gültigen Bereichs.');
        }

        $encryption = strtolower(trim((string)($data['encryption'] ?? 'starttls')));
        if (!in_array($encryption, ['starttls', 'smtps', 'none'], true)) {
            throw new ValidationException('Unbekannter SMTP-Verschlüsselungsmodus.');
        }

        $allowPrivate = !empty($data['allow_private_target']);
        if ($encryption === 'none' && !$allowPrivate) {
            throw new SecurityException('Plaintext SMTP is forbidden for public SMTP targets.');
        }

        $defaultAuthType = isset($preset['auth_type']) && is_string($preset['auth_type']) ? $preset['auth_type'] : 'auto';
        $authType = strtolower(trim((string)($data['auth_type'] ?? $defaultAuthType)));
        if (!in_array($authType, ['auto', 'login', 'plain', 'xoauth2'], true)) {
            throw new ValidationException('Nicht unterstützter SMTP-Authentifizierungstyp.');
        }

        $username = self::cleanCredential((string)($data['username'] ?? ''), 320);
        $password = self::cleanSecret((string)($data['password'] ?? ''), 4096, false);

        $defaultTokenUrl = isset($preset['oauth_token_url']) && is_string($preset['oauth_token_url']) ? $preset['oauth_token_url'] : '';
        $oauthTokenUrl = OAuthEndpointPolicy::normalizeHttpsUrl((string)($data['oauth_token_url'] ?? $defaultTokenUrl));
        $oauthClientId = self::cleanCredential((string)($data['oauth_client_id'] ?? ''), 1024);
        $oauthClientSecret = self::cleanSecret((string)($data['oauth_client_secret'] ?? ''), 8192, false);
        $oauthRefreshToken = self::cleanSecret((string)($data['oauth_refresh_token'] ?? ''), 16384, false);
        $defaultOAuthScope = isset($preset['oauth_scope']) && is_string($preset['oauth_scope']) ? $preset['oauth_scope'] : '';
        $oauthScope = self::cleanOpaqueText((string)($data['oauth_scope'] ?? $defaultOAuthScope), 2048);

        $fromEmail = sanitize_email((string)($data['from_email'] ?? ''));
        $fromName = self::cleanHeaderText((string)($data['from_name'] ?? ''), 180);

        $enabled = !empty($data['enabled']);
        $auth = !empty($data['auth']);
        if ($fromEmail !== '' && !is_email($fromEmail)) {
            throw new ValidationException('Absender-E-Mail ist ungültig.');
        }

        $timeout = filter_var($data['timeout'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['min_range' => 3, 'max_range' => 30]]);
        if ($timeout === false) {
            $timeout = 10;
        }

        $dkimEnabled = !empty($data['dkim_enabled']);
        $dkimDomain = EndpointPolicy::normalizeOptionalDomain((string)($data['dkim_domain'] ?? ''));
        $dkimSelector = trim((string)($data['dkim_selector'] ?? ''));
        if ($dkimSelector !== '' && preg_match('/\A[a-zA-Z0-9._-]{1,63}\z/D', $dkimSelector) !== 1) {
            throw new ValidationException('DKIM-Selector enthält ungültige Zeichen.');
        }
        $dkimIdentity = sanitize_email((string)($data['dkim_identity'] ?? ''));
        $dkimKey = self::cleanSecret((string)($data['dkim_private_key'] ?? ''), 32768, false);
        if ($dkimEnabled) {
            if ($dkimDomain === '' || $dkimSelector === '') {
                throw new ValidationException('DKIM benötigt Domain und Selector.');
            }
            if ($dkimIdentity !== '' && !is_email($dkimIdentity)) {
                throw new ValidationException('DKIM Identity ist ungültig.');
            }
        }

        return new self(
            $enabled,
            $provider,
            $host,
            (int)$port,
            $encryption,
            $auth,
            $authType,
            $username,
            $password,
            $oauthTokenUrl,
            $oauthClientId,
            $oauthClientSecret,
            $oauthRefreshToken,
            $oauthScope,
            $fromEmail,
            $fromName,
            !empty($data['force_from_email']),
            !empty($data['force_from_name']),
            !empty($data['return_path']),
            (int)$timeout,
            $allowPrivate,
            $dkimEnabled,
            $dkimDomain,
            $dkimSelector,
            $dkimIdentity,
            $dkimKey,
        );
    }

    public static function defaults(): self
    {
        $preset = ProviderRegistry::get('custom');
        return new self(
            false,
            'custom',
            '',
            (int)$preset['port'],
            (string)$preset['encryption'],
            true,
            (string)($preset['auth_type'] ?? 'auto'),
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            true,
            true,
            false,
            10,
            false,
            false,
            '',
            '',
            '',
            '',
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema' => 2,
            'enabled' => $this->enabled,
            'provider' => $this->provider,
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'auth' => $this->auth,
            'auth_type' => $this->authType,
            'username' => $this->username,
            'password' => $this->password,
            'oauth_token_url' => $this->oauthTokenUrl,
            'oauth_client_id' => $this->oauthClientId,
            'oauth_client_secret' => $this->oauthClientSecret,
            'oauth_refresh_token' => $this->oauthRefreshToken,
            'oauth_scope' => $this->oauthScope,
            'from_email' => $this->fromEmail,
            'from_name' => $this->fromName,
            'force_from_email' => $this->forceFromEmail,
            'force_from_name' => $this->forceFromName,
            'return_path' => $this->returnPath,
            'timeout' => $this->timeout,
            'allow_private_target' => $this->allowPrivateTarget,
            'dkim_enabled' => $this->dkimEnabled,
            'dkim_domain' => $this->dkimDomain,
            'dkim_selector' => $this->dkimSelector,
            'dkim_identity' => $this->dkimIdentity,
            'dkim_private_key' => $this->dkimPrivateKey,
        ];
    }

    public function assertOperational(bool $validateDkimKey = false): void
    {
        if (!$this->enabled) {
            return;
        }
        if ($this->host === '') {
            throw new ValidationException('SMTP-Host darf bei aktiviertem Gateway nicht leer sein.');
        }
        if ($this->auth && $this->effectiveUsername() === '') {
            throw new ValidationException('SMTP-Benutzername fehlt.');
        }
        if ($this->auth && $this->authType === 'xoauth2') {
            if (Settings::externalOAuthAccessToken() === '') {
                if ($this->oauthTokenUrl === '') {
                    throw new ValidationException('XOAUTH2 benötigt einen OAuth Token Endpoint.');
                }
                if ($this->oauthClientId === '') {
                    throw new ValidationException('XOAUTH2 benötigt eine Client ID.');
                }
                if ($this->effectiveOAuthRefreshToken() === '') {
                    throw new ValidationException('XOAUTH2 benötigt ein Refresh Token oder externes Refresh Token.');
                }
                OAuthEndpointPolicy::assertAllowed($this->oauthTokenUrl);
            }
        } elseif ($this->auth && $this->effectivePassword() === '') {
            throw new ValidationException('SMTP-Passwort oder externes SMTP-Secret fehlt.');
        }
        if ($this->dkimEnabled) {
            $key = $this->effectiveDkimPrivateKey();
            if ($key === '') {
                throw new ValidationException('DKIM Private Key fehlt.');
            }
            if ($validateDkimKey && !self::isValidPrivateKey($key)) {
                throw new ValidationException('DKIM Private Key ist nicht gültig.');
            }
        }
    }

    public function effectiveUsername(): string
    {
        $external = Settings::externalUsername();
        return $external !== '' ? $external : $this->username;
    }

    public function effectivePassword(): string
    {
        $external = Settings::externalPassword();
        return $external !== '' ? $external : $this->password;
    }

    public function effectiveOAuthClientSecret(): string
    {
        $external = Settings::externalOAuthClientSecret();
        return $external !== '' ? $external : $this->oauthClientSecret;
    }

    public function effectiveOAuthRefreshToken(): string
    {
        $external = Settings::externalOAuthRefreshToken();
        return $external !== '' ? $external : $this->oauthRefreshToken;
    }

    public function effectiveDkimPrivateKey(): string
    {
        $external = Settings::externalDkimPrivateKey();
        return $external !== '' ? $external : $this->dkimPrivateKey;
    }

    private static function cleanCredential(string $value, int $max): string
    {
        $value = trim($value);
        if (str_contains($value, "\r") || str_contains($value, "\n") || str_contains($value, "\0") || strlen($value) > $max) {
            throw new SecurityException('Credential validation failed due to control characters or invalid length.');
        }
        return $value;
    }

    private static function cleanSecret(string $value, int $max, bool $trim = true): string
    {
        if ($trim) {
            $value = trim($value);
        }
        if (strlen($value) > $max || str_contains($value, "\0")) {
            throw new SecurityException('Secret validation failed due to invalid length or byte sequence.');
        }
        return $value;
    }

    private static function cleanOpaqueText(string $value, int $max): string
    {
        $value = trim($value);
        if (strlen($value) > $max || str_contains($value, "\0") || str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new SecurityException('OAuth scope validation failed.');
        }
        return $value;
    }

    private static function cleanHeaderText(string $value, int $max): string
    {
        $value = trim(sanitize_text_field($value));
        if (str_contains($value, "\r") || str_contains($value, "\n") || strlen($value) > $max) {
            throw new SecurityException('Header validation failed.');
        }
        return $value;
    }

    private static function isValidPrivateKey(#[\SensitiveParameter] string $key): bool
    {
        if (!function_exists('openssl_pkey_get_private')) {
            return false;
        }
        $resource = @openssl_pkey_get_private($key);
        return $resource !== false;
    }
}
