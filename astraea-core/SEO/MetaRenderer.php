<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\SEO;

/**
 * Clean Technical Meta and OpenGraph Renderer.
 *
 * Emits canonical URLs, robots instructions, OpenGraph, and Twitter cards
 * with zero duplicate output when external SEO plugins are present.
 *
 * @package Astraea\SEO
 */
final class MetaRenderer {

    public static function init(): void {
        add_action('wp_head', [self::class, 'render'], 1);
        add_action('template_redirect', [self::class, 'redirectAttachmentPages']);
    }

    public static function render(): void {
        if (is_admin() || ConflictDetector::hasConflict()) {
            return;
        }

        $canonical = self::getCanonicalUrl();
        $title = self::getTitle();
        $description = self::getDescription();
        $robots = self::getRobots();

        echo "\n<!-- Astraea SEO Essentials -->\n";
        if ($canonical !== '') {
            echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
        }
        if ($description !== '') {
            echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
        }
        if ($robots !== '') {
            echo '<meta name="robots" content="' . esc_attr($robots) . '">' . "\n";
        }

        // OpenGraph
        echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        if ($description !== '') {
            echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
        }
        if ($canonical !== '') {
            echo '<meta property="og:url" content="' . esc_url($canonical) . '">' . "\n";
        }
        echo '<meta property="og:type" content="' . (is_single() ? 'article' : 'website') . '">' . "\n";

        // Image
        $image = self::getImage();
        if ($image !== '') {
            echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
            echo '<meta name="twitter:image" content="' . esc_url($image) . '">' . "\n";
        }

        // Twitter Card
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "\n";
        if ($description !== '') {
            echo '<meta name="twitter:description" content="' . esc_attr($description) . '">' . "\n";
        }
        echo "<!-- /Astraea SEO Essentials -->\n\n";
    }

    public static function redirectAttachmentPages(): void {
        if (is_attachment()) {
            $parent = wp_get_post_parent_id(get_the_ID());
            if ($parent > 0) {
                wp_safe_redirect(get_permalink($parent), 301);
                exit;
            } else {
                wp_safe_redirect(home_url('/'), 301);
                exit;
            }
        }
    }

    private static function getCanonicalUrl(): string {
        if (is_front_page()) {
            return home_url('/');
        }
        if (is_singular()) {
            $link = get_permalink();
            return is_string($link) ? $link : '';
        }
        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term instanceof \WP_Term) {
                $link = get_term_link($term);
                return is_string($link) ? $link : '';
            }
        }
        return '';
    }

    private static function getTitle(): string {
        return wp_get_document_title();
    }

    private static function getDescription(): string {
        if (is_singular()) {
            $post = get_post();
            if ($post instanceof \WP_Post) {
                if ($post->post_excerpt !== '') {
                    return wp_strip_all_tags($post->post_excerpt);
                }
                $content = wp_strip_all_tags($post->post_content);
                return substr($content, 0, 160);
            }
        }
        return get_bloginfo('description');
    }

    private static function getRobots(): string {
        if (get_option('blog_public') === '0') {
            return 'noindex, nofollow';
        }
        if (is_search() || is_404()) {
            return 'noindex, follow';
        }
        if (is_date() || is_author()) {
            // Respect archive indexing option
            if (get_option('astraea_seo_noindex_archives', true)) {
                return 'noindex, follow';
            }
        }
        return 'index, follow, max-image-preview:large';
    }

    private static function getImage(): string {
        if (is_singular()) {
            $thumbId = get_post_thumbnail_id();
            if ($thumbId) {
                $src = wp_get_attachment_image_src($thumbId, 'large');
                if (is_array($src) && isset($src[0])) {
                    return $src[0];
                }
            }
        }
        $fallback = get_option('astraea_seo_default_image', '');
        return is_string($fallback) ? $fallback : '';
    }
}
