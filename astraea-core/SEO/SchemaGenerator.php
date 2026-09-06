<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\SEO;

/**
 * Standard Schema.org JSON-LD Generator.
 *
 * Emits clean, compliant structured data for WebSite, Organization,
 * Article, and BreadcrumbList schemas.
 *
 * @package Astraea\SEO
 */
final class SchemaGenerator {

    public static function init(): void {
        add_action('wp_head', [self::class, 'renderSchema'], 2);
    }

    public static function renderSchema(): void {
        if (is_admin() || ConflictDetector::hasConflict()) {
            return;
        }

        $schemas = [];

        // 1. WebSite & Organization Schema on Front Page
        if (is_front_page()) {
            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type'    => 'WebSite',
                'name'     => get_bloginfo('name'),
                'url'      => home_url('/'),
                'description' => get_bloginfo('description'),
            ];

            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type'    => 'Organization',
                'name'     => get_bloginfo('name'),
                'url'      => home_url('/'),
            ];
        }

        // 2. Article Schema on Single Posts
        if (is_single()) {
            $post = get_post();
            if ($post instanceof \WP_Post) {
                $authorName = get_the_author_meta('display_name', (int)$post->post_author);
                $schemas[] = [
                    '@context'      => 'https://schema.org',
                    '@type'         => 'Article',
                    'headline'      => get_the_title($post),
                    'datePublished' => get_the_date('c', $post),
                    'dateModified'  => get_the_modified_date('c', $post),
                    'author'        => [
                        '@type' => 'Person',
                        'name'  => $authorName ?: 'Staff',
                    ],
                    'publisher'     => [
                        '@type' => 'Organization',
                        'name'  => get_bloginfo('name'),
                    ],
                    'mainEntityOfPage' => get_permalink($post),
                ];
            }
        }

        if (empty($schemas)) {
            return;
        }

        foreach ($schemas as $schema) {
            $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
            $json = function_exists('wp_json_encode')
                ? wp_json_encode($schema, $flags)
                : json_encode($schema, $flags);
            if (is_string($json)) {
                echo '<script type="application/ld+json">' . $json . "</script>\n";
            }
        }
    }
}
