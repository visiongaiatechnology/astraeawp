<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Performance;

/**
 * Native Media Lazy Loading & Async Decoding Adapter.
 *
 * Emits browser-native loading="lazy" and decoding="async" attributes on images
 * and iframes using WordPress's fast, robust WP_HTML_Tag_Processor.
 *
 * @package Astraea\Performance
 */
final class MediaLazyLoader {

    public static function init(): void {
        add_filter('the_content', [self::class, 'processContent'], 20);
        add_filter('post_thumbnail_html', [self::class, 'processContent'], 20);
        add_filter('widget_text', [self::class, 'processContent'], 20);
    }

    /**
     * Augment images and iframes with lazy loading attributes.
     */
    public static function processContent(mixed $content): mixed {
        if (!is_string($content) || $content === '' || !class_exists('\\WP_HTML_Tag_Processor')) {
            return $content;
        }

        $processor = new \WP_HTML_Tag_Processor($content);

        // 1. Process <img> tags
        while ($processor->next_tag(['tag_name' => 'IMG'])) {
            if ($processor->get_attribute('loading') === null) {
                $processor->set_attribute('loading', 'lazy');
            }
            if ($processor->get_attribute('decoding') === null) {
                $processor->set_attribute('decoding', 'async');
            }
        }

        // 2. Process <iframe> tags
        $processor = new \WP_HTML_Tag_Processor($processor->get_updated_html());
        while ($processor->next_tag(['tag_name' => 'IFRAME'])) {
            if ($processor->get_attribute('loading') === null) {
                $processor->set_attribute('loading', 'lazy');
            }
        }

        return $processor->get_updated_html();
    }
}
