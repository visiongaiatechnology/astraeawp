<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Security;

use Astraea\Exceptions\ValidationException;

/**
 * Cryptographically Secure Pseudo-Random Number Generator (CSPRNG) Utilities.
 *
 * Guarantees high-entropy, unpredictable randomness using native random_bytes() and random_int().
 * Strictly forbids mt_rand() or rand() for security-relevant tokens adhering to VGT Section 3.1.
 *
 * @package Astraea\Security
 */
final class Random {

    /**
     * Generate raw binary random bytes.
     *
     * @param int $length Number of bytes.
     * @return string Raw binary string.
     * @throws ValidationException If length is non-positive.
     */
    public static function bytes(int $length = 32): string {
        if ($length < 1) {
            throw new ValidationException('Requested random byte length must be positive.');
        }
        return random_bytes($length);
    }

    /**
     * Generate a cryptographic hexadecimal token.
     *
     * @param int $bytes Number of random bytes (output is double length in hex).
     * @return string Hex-encoded token.
     */
    public static function hex(int $bytes = 32): string {
        return bin2hex(self::bytes($bytes));
    }

    /**
     * Generate a URL-safe Base64 random token.
     *
     * @param int $bytes Number of random bytes.
     * @return string URL-safe Base64 string without padding.
     */
    public static function token(int $bytes = 32): string {
        return rtrim(strtr(base64_encode(self::bytes($bytes)), '+/', '-_'), '=');
    }

    /**
     * Generate an alphanumeric random string.
     *
     * @param int $length Character length.
     * @return string
     * @throws ValidationException If length is non-positive.
     */
    public static function string(int $length = 32): string {
        if ($length < 1) {
            throw new ValidationException('Requested random string length must be positive.');
        }

        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charLen = strlen($chars);
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, $charLen - 1)];
        }

        return $result;
    }
}
