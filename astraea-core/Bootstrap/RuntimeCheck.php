<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Bootstrap;

/**
 * Modern Runtime Baseline Validator for AstraeaOS WP.
 *
 * Enforces:
 * - PHP >= 8.3
 * - 64-bit PHP architecture
 * - Sodium extension
 * - OpenSSL extension
 * - Argon2id native password support
 * - mbstring extension
 * - intl extension
 * - DOM/XML extension
 * - JSON extension
 * - Database driver (mysqli or pdo_mysql)
 *
 * Adheres strictly to VGT Section 3.1 (ENT_QUOTES UTF-8 output encoding).
 *
 * @package Astraea\Bootstrap
 */
final class RuntimeCheck {

    public const MINIMUM_PHP_VERSION = '8.3.0';

    /**
     * Run full runtime verification.
     * Returns an array of requirement checks.
     *
     * @return array<string, array{passed: bool, current: string, required: string, description: string}>
     */
    public static function check(): array {
        return [
            'php_version' => [
                'passed' => version_compare(PHP_VERSION, self::MINIMUM_PHP_VERSION, '>='),
                'current' => PHP_VERSION,
                'required' => '>=' . self::MINIMUM_PHP_VERSION,
                'description' => 'PHP 8.3+ is mandatory for modern typed syntax, security primitives, and memory safety.'
            ],
            'php_64bit' => [
                'passed' => PHP_INT_SIZE === 8,
                'current' => (PHP_INT_SIZE === 8) ? '64-bit' : '32-bit',
                'required' => '64-bit',
                'description' => '64-bit architecture is required for 64-bit integer cryptography and high-entropy hashing.'
            ],
            'sodium' => [
                'passed' => extension_loaded('sodium'),
                'current' => extension_loaded('sodium') ? 'Loaded' : 'Missing',
                'required' => 'Loaded',
                'description' => 'libsodium is required for modern AEAD encryption and constant-time primitives.'
            ],
            'openssl' => [
                'passed' => extension_loaded('openssl'),
                'current' => extension_loaded('openssl') ? OPENSSL_VERSION_TEXT : 'Missing',
                'required' => 'Loaded',
                'description' => 'OpenSSL is required for cryptographic fallbacks, certificate checks, and secure random sources.'
            ],
            'argon2id' => [
                'passed' => defined('PASSWORD_ARGON2ID'),
                'current' => defined('PASSWORD_ARGON2ID') ? 'Supported' : 'Not Supported',
                'required' => 'Supported',
                'description' => 'Native Argon2id password hashing raises the memory and compute cost of offline password cracking.'
            ],
            'mbstring' => [
                'passed' => extension_loaded('mbstring'),
                'current' => extension_loaded('mbstring') ? 'Loaded' : 'Missing',
                'required' => 'Loaded',
                'description' => 'Multibyte string support is required for native utf8mb4 and text operations.'
            ],
            'intl' => [
                'passed' => extension_loaded('intl'),
                'current' => extension_loaded('intl') ? 'Loaded' : 'Missing',
                'required' => 'Loaded',
                'description' => 'Internationalization extension for Unicode normalization and locale formatting.'
            ],
            'dom_xml' => [
                'passed' => extension_loaded('dom') && class_exists(\DOMDocument::class),
                'current' => extension_loaded('dom') && class_exists(\DOMDocument::class) ? 'Loaded' : 'Missing',
                'required' => 'Loaded',
                'description' => 'DOM/XML is required by the allowlist-based SVG sanitizer.',
            ],
            'json' => [
                'passed' => extension_loaded('json'),
                'current' => extension_loaded('json') ? 'Loaded' : 'Missing',
                'required' => 'Loaded',
                'description' => 'Native JSON encoding/decoding.'
            ],
            'db_driver' => [
                'passed' => extension_loaded('mysqli') || extension_loaded('pdo_mysql'),
                'current' => extension_loaded('mysqli') ? 'mysqli' : (extension_loaded('pdo_mysql') ? 'pdo_mysql' : 'None'),
                'required' => 'mysqli or pdo_mysql',
                'description' => 'Modern MySQL/MariaDB database driver.'
            ],
        ];
    }

    /**
     * Assert baseline compliance. If any requirement fails, display a clean error and halt.
     */
    public static function assertBaseline(): void {
        $checks = self::check();
        $failures = [];

        foreach ($checks as $key => $check) {
            if (!$check['passed']) {
                $failures[$key] = $check;
            }
        }

        if (empty($failures)) {
            return;
        }

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "[AstraeaOS WP] FATAL: Runtime baseline requirements not met:\n");
            foreach ($failures as $key => $fail) {
                fwrite(STDERR, sprintf("  - %s: required %s, current: %s (%s)\n", $key, $fail['required'], $fail['current'], $fail['description']));
            }
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }

        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>AstraeaOS WP — System Requirements Failure</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0b0f19; color: #e2e8f0; margin: 0; padding: 40px 20px; }
                .card { max-width: 720px; margin: 0 auto; background: #161e2e; border: 1px solid #2d3748; border-radius: 12px; padding: 32px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
                h1 { color: #f87171; font-size: 24px; margin-top: 0; }
                p { line-height: 1.6; color: #94a3b8; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { text-align: left; padding: 12px; border-bottom: 1px solid #2d3748; font-size: 14px; }
                th { color: #cbd5e1; }
                .fail { color: #f87171; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class="card">
                <h1>AstraeaOS WP — Environment Requirements Not Met</h1>
                <p>AstraeaOS WP is a security-hardened WordPress distribution designed for a modern, explicitly validated server baseline.</p>
                <table>
                    <thead>
                        <tr><th>Requirement</th><th>Required</th><th>Detected</th><th>Details</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($failures as $name => $fail): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></strong></td>
                                <td><?= htmlspecialchars($fail['required'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="fail"><?= htmlspecialchars($fail['current'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($fail['description'], ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin-top: 24px;">Please upgrade your PHP runtime environment to PHP 8.3+ 64-bit with sodium and openssl extensions enabled.</p>
            </div>
        </body>
        </html>
        <?php
        exit(1);
    }

    /**
     * Verify database server version requirement (MySQL >= 8.0 or MariaDB >= 10.11).
     *
     * @param string $versionString e.g. "8.0.36" or "10.11.6-MariaDB"
     * @return array{passed: bool, type: string, version: string, required: string}
     */
    public static function checkDatabaseServerVersion(string $versionString): array {
        $clean = strtolower($versionString);
        $isMariaDb = str_contains($clean, 'mariadb');

        if (preg_match('/(\d+\.\d+(\.\d+)?)/', $versionString, $matches)) {
            $version = $matches[1];
        } else {
            $version = '0.0.0';
        }

        if ($isMariaDb) {
            $required = '10.11';
            $passed = version_compare($version, $required, '>=');
            $type = 'MariaDB';
        } else {
            $required = '8.0';
            $passed = version_compare($version, $required, '>=');
            $type = 'MySQL';
        }

        return [
            'passed'   => $passed,
            'type'     => $type,
            'version'  => $versionString,
            'required' => "{$type} >= {$required}",
        ];
    }
}
