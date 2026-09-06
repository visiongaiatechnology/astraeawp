<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Migration;

/**
 * Intelligent Third-Party Plugin Replacement Analyzer for AstraeaOS.
 *
 * Evaluates installed plugins against Astraea first-party kernel systems,
 * projecting reductions in supply-chain attack surface and plugin overhead.
 *
 * @package Astraea\Migration
 */
final class PluginReplacementAnalyzer {

    /**
     * Map of known third-party plugin slugs/patterns to native Astraea replacements.
     *
     * @var array<string, array{module: string, module_name: string, category: string, benefit: string}>
     */
    private const REPLACEMENT_MAP = [
        // SMTP / Mail
        'wp-mail-smtp'            => ['module' => 'mail', 'module_name' => 'Astraea Mail Gateway', 'category' => 'Mail Transport', 'benefit' => 'Kernel-native encrypted SMTP with zero telemetry.'],
        'easy-wp-smtp'            => ['module' => 'mail', 'module_name' => 'Astraea Mail Gateway', 'category' => 'Mail Transport', 'benefit' => 'Hardware-accelerated AEAD encrypted credentials.'],
        'post-smtp'               => ['module' => 'mail', 'module_name' => 'Astraea Mail Gateway', 'category' => 'Mail Transport', 'benefit' => 'Zero external SDK dependencies.'],

        // Consent / Privacy
        'complianz-gdpr'          => ['module' => 'vlp', 'module_name' => 'VLP Light', 'category' => 'Consent & Privacy', 'benefit' => 'Native server-side tag processor with HttpOnly HMAC receipts.'],
        'borlabs-cookie'          => ['module' => 'vlp', 'module_name' => 'VLP Light', 'category' => 'Consent & Privacy', 'benefit' => 'Synchronous DOM gatekeeper without cloud phoning.'],
        'cookiebot'               => ['module' => 'vlp', 'module_name' => 'VLP Light', 'category' => 'Consent & Privacy', 'benefit' => 'Eliminates third-party script loading latency.'],
        'cookie-law-info'         => ['module' => 'vlp', 'module_name' => 'VLP Light', 'category' => 'Consent & Privacy', 'benefit' => 'Glassmorphism UI styled to system design tokens.'],

        // Analytics
        'google-site-kit'         => ['module' => 'dattrack', 'module_name' => 'Dattrack Light', 'category' => 'Privacy Analytics', 'benefit' => 'Zero external Google trackers; GDPR-compliant local event storage.'],
        'google-analytics-for-wordpress' => ['module' => 'dattrack', 'module_name' => 'Dattrack Light', 'category' => 'Privacy Analytics', 'benefit' => 'Pseudonymous daily HMAC visitor hashing; no raw IP storage.'],
        'wp-statistics'           => ['module' => 'dattrack', 'module_name' => 'Dattrack Light', 'category' => 'Privacy Analytics', 'benefit' => 'AEAD encrypted database records with automatic 7-day retention.'],

        // Backups
        'updraftplus'             => ['module' => 'vault', 'module_name' => 'Astraea Vault', 'category' => 'Disaster Recovery', 'benefit' => 'Atomic pre-update snapshots with autonomous pre-plugin rollback.'],
        'backwpup'                => ['module' => 'vault', 'module_name' => 'Astraea Vault', 'category' => 'Disaster Recovery', 'benefit' => 'Domain-separated cryptographic backup archives.'],
        'duplicator'              => ['module' => 'vault', 'module_name' => 'Astraea Vault', 'category' => 'Disaster Recovery', 'benefit' => 'Core-native recovery environment without plugin dependencies.'],

        // Security
        'wordfence'               => ['module' => 'gedefense', 'module_name' => 'GeDefense', 'category' => 'Security & Firewall', 'benefit' => 'Multi-layer Cerberus L0, Aegis DPI, and Titan HTTP security.'],
        'better-wp-security'      => ['module' => 'gedefense', 'module_name' => 'GeDefense', 'category' => 'Security & Firewall', 'benefit' => 'Real-time Security Event Fabric with zero synthetic health metrics.'],
        'sucuri-scanner'          => ['module' => 'gedefense', 'module_name' => 'GeDefense', 'category' => 'Security & Firewall', 'benefit' => 'Built-in build-manifest file integrity and polyglot upload shields.'],

        // Performance & Caching
        'wp-rocket'               => ['module' => 'performance', 'module_name' => 'Astraea Performance Engine', 'category' => 'Page & Object Cache', 'benefit' => 'Kernel-level disk caching with strict consent-aware variation.'],
        'w3-total-cache'          => ['module' => 'performance', 'module_name' => 'Astraea Performance Engine', 'category' => 'Page & Object Cache', 'benefit' => 'Zero-bloat browser cache policy and native lazy-loading.'],
        'wp-super-cache'          => ['module' => 'performance', 'module_name' => 'Astraea Performance Engine', 'category' => 'Page & Object Cache', 'benefit' => 'Non-destructive HTML whitespace optimization.'],
        'litespeed-cache'         => ['module' => 'performance', 'module_name' => 'Astraea Performance Engine', 'category' => 'Page & Object Cache', 'benefit' => 'Adaptive admin and frontend heartbeat throttling.'],

        // Redirects
        'redirection'             => ['module' => 'redirects', 'module_name' => 'Astraea Redirect Manager', 'category' => 'URL Routing', 'benefit' => 'ReDoS-immune regex and loop-detecting 301/302/307/308 redirects.'],
        'simple-301-redirects'    => ['module' => 'redirects', 'module_name' => 'Astraea Redirect Manager', 'category' => 'URL Routing', 'benefit' => 'Automatic post slug change tracking and privacy-safe 404 logs.'],

        // SEO
        'wordpress-seo'           => ['module' => 'seo', 'module_name' => 'Astraea SEO Essentials', 'category' => 'Search Engine Optimization', 'benefit' => 'Technical meta, OpenGraph, Schema JSON-LD without content scores.'],
        'seo-by-rank-math'        => ['module' => 'seo', 'module_name' => 'Astraea SEO Essentials', 'category' => 'Search Engine Optimization', 'benefit' => 'Core XML sitemap enhancements with zero upsell bloat.'],
        'all-in-one-seo-pack'     => ['module' => 'seo', 'module_name' => 'Astraea SEO Essentials', 'category' => 'Search Engine Optimization', 'benefit' => 'Clean canonical URLs and attachment page redirection.'],

        // Forms
        'contact-form-7'          => ['module' => 'forms', 'module_name' => 'Astraea Forms Light', 'category' => 'Form Engine', 'benefit' => 'Honeypot, CSRF nonce, FileGuard upload shields, encrypted storage.'],
        'wpforms-lite'            => ['module' => 'forms', 'module_name' => 'Astraea Forms Light', 'category' => 'Form Engine', 'benefit' => 'Native mail gateway delivery without third-party add-ons.'],
        'ninja-forms'             => ['module' => 'forms', 'module_name' => 'Astraea Forms Light', 'category' => 'Form Engine', 'benefit' => 'Pure local vanilla JS and Glassmorphism design.'],

        // Database
        'wp-optimize'             => ['module' => 'database', 'module_name' => 'Astraea Database Maintenance', 'category' => 'DB Maintenance', 'benefit' => 'Dry-run preview with mandatory Vault snapshot before cleanup.'],
        'advanced-database-cleaner' => ['module' => 'database', 'module_name' => 'Astraea Database Maintenance', 'category' => 'DB Maintenance', 'benefit' => 'Autoload option size analysis and orphan record cleanup.'],
    ];

    /**
     * Analyze active plugins and return potential replacements.
     *
     * @param list<string> $activePlugins Plugin relative paths (e.g. 'wp-mail-smtp/wp_mail_smtp.php')
     * @return array<string, mixed>
     */
    public static function analyze(array $activePlugins): array {
        $totalPlugins = count($activePlugins);
        $replaceable = [];
        $unmatched = [];

        foreach ($activePlugins as $pluginPath) {
            $slug = dirname($pluginPath);
            if ($slug === '.') {
                $slug = basename($pluginPath, '.php');
            }

            $matched = false;
            foreach (self::REPLACEMENT_MAP as $pattern => $info) {
                if (str_contains($slug, $pattern) || str_contains($pluginPath, $pattern)) {
                    $replaceable[] = [
                        'plugin_file' => $pluginPath,
                        'plugin_slug' => $slug,
                        'module_id'   => $info['module'],
                        'module_name' => $info['module_name'],
                        'category'    => $info['category'],
                        'benefit'     => $info['benefit'],
                        'status'      => 'Can potentially be replaced',
                    ];
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $unmatched[] = [
                    'plugin_file' => $pluginPath,
                    'plugin_slug' => $slug,
                    'status'      => 'Specialized application (Retain as plugin)',
                ];
            }
        }

        $replaceableCount = count($replaceable);
        $projectedCount = $totalPlugins - $replaceableCount;

        return [
            'total_before'      => $totalPlugins,
            'replaceable_count' => $replaceableCount,
            'total_after'       => $projectedCount,
            'replaceable'       => $replaceable,
            'retained'          => $unmatched,
        ];
    }
}
