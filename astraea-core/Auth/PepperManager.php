<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Auth;

/**
 * Server-Side Password Pepper Manager.
 *
 * Implements an optional defense-in-depth secret pepper stored strictly
 * outside the database (environment variable or protected server file).
 *
 * Pre-hashes the password via HMAC-SHA384 with the pepper, protecting against
 * offline dictionary attacks even if the database is completely compromised.
 *
 * @package Astraea\Auth
 */
final class PepperManager {

    private static ?string $pepper = null;
    private static bool $checked = false;
    /** @var list<string>|null */
    private static ?array $previousPeppers = null;

    /**
     * Set the pepper explicitly (e.g. during testing or bootstrap).
     */
    public static function setPepper(?string $pepper): void {
        self::$pepper = $pepper;
        self::$checked = true;
        self::$previousPeppers = null;
    }

    /**
     * Retrieve the server-side pepper if configured.
     *
     * @return string|null Pepper string or null if not in use.
     */
    public static function getPepper(): ?string {
        if (self::$checked) {
            return self::$pepper;
        }

        self::$checked = true;

        // 1. Check environment variable
        $env = getenv('ASTRAEA_PASSWORD_PEPPER');
        if (is_string($env) && trim($env) !== '') {
            self::$pepper = trim($env);
            return self::$pepper;
        }

        // 2. Check constant
        if (defined('ASTRAEA_PASSWORD_PEPPER') && is_string(ASTRAEA_PASSWORD_PEPPER) && trim(ASTRAEA_PASSWORD_PEPPER) !== '') {
            self::$pepper = trim(ASTRAEA_PASSWORD_PEPPER);
            return self::$pepper;
        }

        // 3. Check external file path
        $filePath = getenv('ASTRAEA_PASSWORD_PEPPER_FILE');
        if (!is_string($filePath) && defined('ASTRAEA_PASSWORD_PEPPER_FILE')) {
            $filePath = ASTRAEA_PASSWORD_PEPPER_FILE;
        }
        if (is_string($filePath) && is_file($filePath) && is_readable($filePath)) {
            $content = file_get_contents($filePath);
            if (is_string($content) && trim($content) !== '') {
                self::$pepper = trim($content);
                return self::$pepper;
            }
        }

        self::$pepper = null;
        return null;
    }

    /**
     * Historical peppers are accepted only for migration verification. They are
     * sourced outside the database. Preferred format is one secret per line in
     * ASTRAEA_PASSWORD_PEPPER_PREVIOUS_FILE. A JSON array may alternatively be
     * supplied through ASTRAEA_PASSWORD_PEPPER_PREVIOUS.
     *
     * @return list<string>
     */
    public static function getPreviousPeppers(): array {
        if (self::$previousPeppers !== null) {
            return self::$previousPeppers;
        }

        $result = [];
        $json = getenv('ASTRAEA_PASSWORD_PEPPER_PREVIOUS');
        if (is_string($json) && trim($json) !== '') {
            try {
                $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    foreach ($decoded as $value) {
                        if (is_string($value) && $value !== '') {
                            $result[] = $value;
                        }
                    }
                }
            } catch (\JsonException) {
                // Invalid external configuration is ignored here; the security
                // probe surfaces migration readiness without exposing contents.
            }
        }

        $file = getenv('ASTRAEA_PASSWORD_PEPPER_PREVIOUS_FILE');
        if (!is_string($file) && defined('ASTRAEA_PASSWORD_PEPPER_PREVIOUS_FILE')) {
            $file = ASTRAEA_PASSWORD_PEPPER_PREVIOUS_FILE;
        }
        if (is_string($file) && $file !== '' && is_file($file) && is_readable($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    if (is_string($line) && $line !== '') {
                        $result[] = $line;
                    }
                }
            }
        }

        $current = self::getPepper();
        $deduped = [];
        foreach ($result as $candidate) {
            if ($current !== null && hash_equals($current, $candidate)) {
                continue;
            }
            $fingerprint = hash('sha256', $candidate);
            $deduped[$fingerprint] = $candidate;
            if (count($deduped) >= 4) {
                break;
            }
        }
        self::$previousPeppers = array_values($deduped);
        return self::$previousPeppers;
    }

    public static function prepareWithPepper(
        #[\SensitiveParameter]
        string $password,
        #[\SensitiveParameter]
        string $pepper
    ): string {
        if ($pepper === '') {
            return $password;
        }

        return base64_encode(hash_hmac('sha384', $password, $pepper, true));
    }

    /**
     * Check if a pepper is currently active.
     */
    public static function isEnabled(): bool {
        return self::getPepper() !== null;
    }

    /**
     * Alias for isEnabled() for consistency across security probes.
     */
    public static function hasPepper(): bool {
        return self::isEnabled();
    }

    /**
     * Prepare password for hashing or verification.
     *
     * If pepper is enabled:
     * Returns base64_encode(hash_hmac('sha384', $password, $pepper, true)).
     * If pepper is disabled:
     * Returns raw password bytes directly (preserving intentional whitespace).
     *
     * @param string $password Plaintext password.
     * @return string Prepared password string.
     */
    public static function preparePassword(
        #[\SensitiveParameter]
        string $password
    ): string {
        $pepper = self::getPepper();

        if ($pepper === null) {
            return $password;
        }

        return self::prepareWithPepper($password, $pepper);
    }
}
