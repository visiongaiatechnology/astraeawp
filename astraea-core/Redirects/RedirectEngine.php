<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Redirects;

use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\ValidationException;

/**
 * ReDoS-Immune, Loop-Aware Redirect Execution Engine.
 *
 * Supports 301, 302, 307, 308 redirects with ReDoS protection,
 * loop and chain cycle detection, and open-redirect boundary enforcement.
 *
 * @package Astraea\Redirects
 */
final class RedirectEngine {

    public const OPTION_RULES = 'astraea_redirect_rules';
    public const MAX_REGEX_LENGTH = 128;
    public const MAX_CHAIN_DEPTH = 5;

    public static function init(): void {
        add_action('template_redirect', [self::class, 'handleRequest'], 1);
    }

    /**
     * Inspect incoming request URI and perform redirect if rule matches.
     */
    public static function handleRequest(): void {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        $rules = self::getRules();
        foreach ($rules as $rule) {
            if (!$rule->active) {
                continue;
            }

            if (self::matches($rule, $path, $targetUrl)) {
                // Loop Detection
                if (self::detectLoop($rule, $path, $rules)) {
                    continue; // Skip looping redirect rule
                }

                self::incrementHit($rule->id);

                // Open redirect protection: validate target host boundaries
                if (str_starts_with($targetUrl, '/') && !str_starts_with($targetUrl, '//')) {
                    $targetUrl = home_url($targetUrl);
                }

                $safeTarget = function_exists('wp_validate_redirect')
                    ? wp_validate_redirect($targetUrl, home_url('/'))
                    : $targetUrl;

                if (function_exists('wp_safe_redirect')) {
                    wp_safe_redirect($safeTarget, $rule->code);
                } elseif (function_exists('wp_redirect')) {
                    wp_redirect($safeTarget, $rule->code);
                }
                exit;
            }
        }
    }

    /**
     * Check if a path matches the rule, resolving any wildcards or regex captures.
     */
    public static function matches(RedirectRule $rule, string $path, ?string &$targetUrl): bool {
        $source = $rule->source;
        $target = $rule->target;

        if ($rule->matchType === RedirectRule::MATCH_EXACT) {
            if (rtrim($path, '/') === rtrim($source, '/')) {
                $targetUrl = $target;
                return true;
            }
            return false;
        }

        if ($rule->matchType === RedirectRule::MATCH_WILDCARD) {
            $prefix = rtrim(str_replace('*', '', $source), '/');
            if (str_starts_with($path, $prefix)) {
                $remainder = substr($path, strlen($prefix));
                $targetUrl = str_replace('*', ltrim($remainder, '/'), $target);
                return true;
            }
            return false;
        }

        if ($rule->matchType === RedirectRule::MATCH_REGEX) {
            self::assertRegexSafe($source);

            $escapedSource = str_replace('#', '\#', $source);
            $pattern = '#(*LIMIT_MATCH=10000)(*LIMIT_DEPTH=128)' . $escapedSource . '#i';
            $matched = @preg_match($pattern, $path, $matches);
            if ($matched === 1) {
                $targetUrl = preg_replace($pattern, $target, $path);
                return is_string($targetUrl);
            }
        }

        return false;
    }

    /**
     * ReDoS protection: inspect regex pattern for catastrophic backtracking patterns.
     */
    public static function assertRegexSafe(string $pattern): void {
        if (strlen($pattern) > self::MAX_REGEX_LENGTH) {
            throw new SecurityException('Regex pattern exceeds maximum allowed length (128 characters).');
        }

        if (str_contains($pattern, '(*') || preg_match('/\\\\[1-9]|\\(\\?(?:R|0|&|P>)/i', $pattern) === 1) {
            throw new SecurityException('Advanced stateful regular-expression constructs are forbidden.');
        }

        // Detect nested quantifiers such as (a+)+, (.*)*, ([a-z]+)+, (a+){2,}
        if (preg_match('/(\([^)]*[\+\*\{][^)]*\))[\+\*\{]/', $pattern)) {
            throw new SecurityException('Dangerous nested quantifier detected (ReDoS vector).');
        }

        // Detect nested repetitions with alternation e.g. (a|a)+
        if (preg_match('/\((?:[^()|]+\|)+[^()|]+\)[\+\*\{]/', $pattern)) {
            throw new SecurityException('Dangerous alternating quantifier detected (ReDoS vector).');
        }

        // Test compilation
        $prev = error_reporting(0);
        $valid = @preg_match('#(*LIMIT_MATCH=10000)(*LIMIT_DEPTH=128)' . str_replace('#', '\#', $pattern) . '#', '');
        error_reporting($prev);

        if ($valid === false) {
            throw new ValidationException('Invalid regular expression pattern.');
        }

        // Backtrack limit pre-flight check
        $orig = ini_get('pcre.backtrack_limit');
        try {
            ini_set('pcre.backtrack_limit', '2000');
            $testStr = str_repeat('a', 100) . '!';
            $testMatch = @preg_match('#(*LIMIT_MATCH=2000)(*LIMIT_DEPTH=64)' . str_replace('#', '\#', $pattern) . '#i', $testStr);
            if ($testMatch === false && preg_last_error() !== PREG_NO_ERROR) {
                throw new SecurityException('Regex pattern triggered ReDoS backtrack limit violation.');
            }
        } finally {
            ini_set('pcre.backtrack_limit', (string)$orig);
        }
    }

    /**
     * Detect redirect loops and cyclic chains.
     *
     * @param list<RedirectRule> $allRules
     */
    public static function detectLoop(RedirectRule $rule, string $initialPath, array $allRules): bool {
        $visited = [$initialPath => true];
        $current = $rule->target;
        $depth = 0;

        while ($depth < self::MAX_CHAIN_DEPTH) {
            $currentPath = parse_url($current, PHP_URL_PATH) ?? $current;
            if (isset($visited[$currentPath])) {
                return true; // Loop detected
            }
            $visited[$currentPath] = true;

            $nextFound = false;
            foreach ($allRules as $candidate) {
                if ($candidate->active && self::matches($candidate, $currentPath, $nextTarget)) {
                    $current = $nextTarget;
                    $nextFound = true;
                    break;
                }
            }

            if (!$nextFound) {
                return false; // Chain terminates safely
            }
            $depth++;
        }

        return true; // Exceeded max chain depth (treat as loop)
    }

    /**
     * @return list<RedirectRule>
     */
    public static function getRules(): array {
        $raw = function_exists('get_option') ? get_option(self::OPTION_RULES, []) : [];
        if (!is_array($raw)) {
            return [];
        }

        $rules = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                try {
                    $rules[] = RedirectRule::fromArray($item);
                } catch (\Throwable) {
                    continue;
                }
            }
        }
        return $rules;
    }

    public static function saveRule(RedirectRule $rule): void {
        $rules = self::getRules();
        $updated = [];
        $found = false;

        foreach ($rules as $existing) {
            if ($existing->id === $rule->id) {
                $updated[] = $rule;
                $found = true;
            } else {
                $updated[] = $existing;
            }
        }

        if (!$found) {
            $updated[] = $rule;
        }

        $raw = array_map(static fn(RedirectRule $r): array => $r->toArray(), $updated);
        update_option(self::OPTION_RULES, $raw);
    }

    public static function deleteRule(string $ruleId): void {
        $rules = self::getRules();
        $filtered = array_filter($rules, static fn(RedirectRule $r): bool => $r->id !== $ruleId);
        $raw = array_map(static fn(RedirectRule $r): array => $r->toArray(), array_values($filtered));
        update_option(self::OPTION_RULES, $raw);
    }

    private static function incrementHit(string $ruleId): void {
        $rules = self::getRules();
        $updated = [];
        foreach ($rules as $r) {
            if ($r->id === $ruleId) {
                $updated[] = new RedirectRule(
                    $r->id, $r->source, $r->target, $r->code, $r->matchType,
                    $r->hits + 1, $r->created, $r->active
                );
            } else {
                $updated[] = $r;
            }
        }
        $raw = array_map(static fn(RedirectRule $r): array => $r->toArray(), $updated);
        update_option(self::OPTION_RULES, $raw);
    }
}
