<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Performance;

/**
 * Non-destructive HTML Whitespace Optimizer.
 *
 * Safely compresses redundant whitespace while preserving the exact contents
 * and syntax semantics of <script>, <style>, <pre>, <code> and <textarea> blocks.
 *
 * @package Astraea\Performance
 */
final class HtmlOptimizer {

    /**
     * Safely optimize an HTML payload without breaking inline scripts or formatting.
     */
    public static function optimize(string $html): string {
        if (strlen($html) < 200) {
            return $html;
        }

        // Placeholder stash for sensitive tags that must remain byte-exact
        $stash = [];
        $index = 0;

        // Extract sensitive blocks: <script>, <style>, <pre>, <textarea>, <code>, and conditional comments
        $pattern = '/<(script|style|pre|textarea|code)\b[^>]*>.*?<\/\1>|<!--\[if.*?\]>.*?<!\[endif\]-->/is';
        $cleaned = preg_replace_callback($pattern, function(array $matches) use (&$stash, &$index): string {
            $token = '___ASTRAEA_PRESERVE_' . (++$index) . '___';
            $stash[$token] = $matches[0];
            return $token;
        }, $html);

        if (!is_string($cleaned)) {
            return $html; // Fallback safely to unmodified string if regex fails
        }

        // Remove standard HTML comments (excluding preservation markers)
        $cleaned = preg_replace('/<!--(?!\s*___ASTRAEA_PRESERVE_).*?-->/s', '', $cleaned);
        if (!is_string($cleaned)) {
            return $html;
        }

        // Collapse multi-spaces and newlines into single spaces between tags
        $cleaned = preg_replace('/>\s+</s', '> <', $cleaned);
        $cleaned = preg_replace('/[ \t]+/s', ' ', (string)$cleaned);

        // Restore stashed blocks exactly
        if (!empty($stash)) {
            $cleaned = str_replace(array_keys($stash), array_values($stash), (string)$cleaned);
        }

        return (string)$cleaned;
    }
}
