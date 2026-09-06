<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Media;

/**
 * Server Media Format Capability Detector.
 *
 * Verifies live host codec support for WebP and AVIF without synthetic claims.
 *
 * @package Astraea\Media
 */
final class FormatCapabilities {

    public const STATUS_SUPPORTED   = 'SUPPORTED';
    public const STATUS_UNSUPPORTED = 'UNSUPPORTED';
    public const STATUS_UNKNOWN     = 'UNKNOWN';

    /**
     * Check if WebP decode/encode is supported on the host.
     *
     * @return string One of STATUS_SUPPORTED, STATUS_UNSUPPORTED, STATUS_UNKNOWN
     */
    public static function checkWebp(): string {
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            return self::STATUS_UNSUPPORTED;
        }

        if (function_exists('imagecreatefromwebp') && function_exists('imagewebp')) {
            return self::STATUS_SUPPORTED;
        }

        if (extension_loaded('imagick') && class_exists('\\Imagick')) {
            try {
                $im = new \Imagick();
                $formats = $im->queryFormats('WEBP');
                return !empty($formats) ? self::STATUS_SUPPORTED : self::STATUS_UNSUPPORTED;
            } catch (\Throwable) {
                return self::STATUS_UNKNOWN;
            }
        }

        return self::STATUS_UNSUPPORTED;
    }

    /**
     * Check if AVIF decode/encode is supported on the host.
     *
     * @return string One of STATUS_SUPPORTED, STATUS_UNSUPPORTED, STATUS_UNKNOWN
     */
    public static function checkAvif(): string {
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            return self::STATUS_UNSUPPORTED;
        }

        if (function_exists('imagecreatefromavif') && function_exists('imageavif')) {
            return self::STATUS_SUPPORTED;
        }

        if (extension_loaded('imagick') && class_exists('\\Imagick')) {
            try {
                $im = new \Imagick();
                $formats = $im->queryFormats('AVIF');
                return !empty($formats) ? self::STATUS_SUPPORTED : self::STATUS_UNSUPPORTED;
            } catch (\Throwable) {
                return self::STATUS_UNKNOWN;
            }
        }

        return self::STATUS_UNSUPPORTED;
    }

    /**
     * Full capability matrix.
     *
     * @return array<string, string>
     */
    public static function matrix(): array {
        return [
            'jpeg' => (function_exists('imagecreatefromjpeg') && function_exists('imagejpeg')) ? self::STATUS_SUPPORTED : self::STATUS_UNSUPPORTED,
            'png'  => (function_exists('imagecreatefrompng') && function_exists('imagepng')) ? self::STATUS_SUPPORTED : self::STATUS_UNSUPPORTED,
            'gif'  => (function_exists('imagecreatefromgif') && function_exists('imagegif')) ? self::STATUS_SUPPORTED : self::STATUS_UNSUPPORTED,
            'webp' => self::checkWebp(),
            'avif' => self::checkAvif(),
        ];
    }
}
