<?php
declare(strict_types=1);

namespace Astraea\Tests;

/**
 * Lightweight, zero-dependency test assertion library for AstraeaOS WP.
 */
class TestCase {
    private static int $assertions = 0;
    private static int $failures = 0;
    /** @var string[] */
    private static array $messages = [];

    public static function assertTrue(bool $condition, string $message = 'Expected true, got false'): void {
        self::$assertions++;
        if (!$condition) {
            self::$failures++;
            self::$messages[] = 'FAIL: ' . $message;
        }
    }

    public static function assertFalse(bool $condition, string $message = 'Expected false, got true'): void {
        self::assertTrue(!$condition, $message);
    }

    public static function assertEquals(mixed $expected, mixed $actual, string $message = ''): void {
        self::$assertions++;
        if ($expected !== $actual) {
            self::$failures++;
            $msg = $message ?: sprintf('Expected %s, got %s', var_export($expected, true), var_export($actual, true));
            self::$messages[] = 'FAIL: ' . $msg;
        }
    }

    public static function assertStringStartsWith(string $prefix, string $string, string $message = ''): void {
        self::assertTrue(str_starts_with($string, $prefix), $message ?: sprintf('String "%s" does not start with "%s"', substr($string, 0, 30), $prefix));
    }

    public static function assertStringContains(string $needle, string $haystack, string $message = ''): void {
        self::assertTrue(str_contains($haystack, $needle), $message ?: sprintf('String does not contain "%s"', $needle));
    }

    public static function getStats(): array {
        return [
            'assertions' => self::$assertions,
            'failures'   => self::$failures,
            'messages'   => self::$messages,
        ];
    }
}
