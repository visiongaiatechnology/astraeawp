<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Media;

use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\ValidationException;
use Astraea\Exceptions\StorageException;
use finfo;

/**
 * Hardened Multi-Stage Upload Security & Image Re-Encode Pipeline.
 *
 * Enforces VGT Mandatory Code Patterns:
 * - 1.5.A Exception Hierarchy
 * - 1.5.B Measured File Size on Temp File
 * - 1.5.D MIME Cross-Check via Integer Type Constants
 * - 1.5.E Realpath Post-Construction Jail Checking
 *
 * @package Astraea\Media
 */
final class UploadPipeline {

    public const MAX_BYTES = 10485760; // 10 MB default max upload

    /**
     * Process an incoming uploaded image through the security verification pipeline.
     *
     * @param array{name: string, tmp_name: string} $file
     * @param string $destinationDir Target directory (must be verified jail)
     * @param int $maxBytes
     * @return array{filename: string, path: string, width: int, height: int, mime: string}
     * @throws SecurityException
     * @throws ValidationException
     * @throws StorageException
     */
    public static function process(array $file, string $destinationDir, int $maxBytes = self::MAX_BYTES): array {
        $tmpPath = $file['tmp_name'] ?? '';
        if ($tmpPath === '' || !is_file($tmpPath) || (php_sapi_name() !== 'cli' && !is_uploaded_file($tmpPath))) {
            throw new ValidationException('Invalid upload temporary file.');
        }

        // 1. PATTERN 1.5.B — File Size Validation on Actual Temp File
        $realSize = filesize($tmpPath);
        if ($realSize === false || $realSize === 0 || $realSize > $maxBytes) {
            throw new ValidationException('Size boundary violation.');
        }

        // 2. MIME Detection from file contents (ignoring client header)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($tmpPath);
        if (!is_string($detectedMime)) {
            throw new SecurityException('MIME detection failed.');
        }

        // 3. Handle SVG specifically through dedicated sanitizer
        if ($detectedMime === 'image/svg+xml' || str_ends_with(strtolower($file['name'] ?? ''), '.svg')) {
            return self::processSvgUpload($tmpPath, $destinationDir);
        }

        // 4. PATTERN 1.5.D — MIME Cross-Check via Integer Constants (Anti-Polyglot)
        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false || !isset($imageInfo[0], $imageInfo[1], $imageInfo[2])) {
            throw new SecurityException('Invalid image header. Decryption/parsing failed.');
        }

        $expectedType = match ($detectedMime) {
            'image/jpeg' => IMAGETYPE_JPEG,
            'image/png'  => IMAGETYPE_PNG,
            'image/webp' => defined('IMAGETYPE_WEBP') ? IMAGETYPE_WEBP : 18,
            'image/gif'  => IMAGETYPE_GIF,
            default      => null,
        };

        if ($expectedType === null || $imageInfo[2] !== $expectedType) {
            throw new SecurityException('MIME/type mismatch. Polyglot vector blocked.');
        }

        $w = (int)$imageInfo[0];
        $h = (int)$imageInfo[1];

        // 5. Memory Pre-Flight Calculation: ($w * $h * 4 * 1.5) + 10MB
        self::assertMemoryPreflight($w, $h);

        // 6. Safe Decode & Polyglot-Stripping Re-Encode via GD
        $imageResource = match ($expectedType) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpPath),
            IMAGETYPE_PNG  => @imagecreatefrompng($tmpPath),
            IMAGETYPE_GIF  => @imagecreatefromgif($tmpPath),
            default        => (function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false),
        };

        if ($imageResource === false) {
            throw new SecurityException('Failed to safely decode image buffer.');
        }

        // 7. PATTERN 1.5.E — Path Jail Enforcement
        $resolvedDir = realpath($destinationDir);
        if ($resolvedDir === false || !is_dir($resolvedDir)) {
            imagedestroy($imageResource);
            throw new StorageException('Destination directory is invalid or unavailable.');
        }

        $extension = match ($expectedType) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_GIF  => 'gif',
            default        => 'webp',
        };

        $safeFilename = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $resolvedDir . DIRECTORY_SEPARATOR . $safeFilename;

        if (!str_starts_with($destination, $resolvedDir . DIRECTORY_SEPARATOR)) {
            imagedestroy($imageResource);
            throw new SecurityException('Path escaped jail.');
        }

        // 8. Safe Re-Encode Write with umask(0077)
        $oldUmask = umask(0077);
        try {
            $written = match ($expectedType) {
                IMAGETYPE_JPEG => imagejpeg($imageResource, $destination, 85),
                IMAGETYPE_PNG  => imagepng($imageResource, $destination, 6),
                IMAGETYPE_GIF  => imagegif($imageResource, $destination),
                default        => imagewebp($imageResource, $destination, 85),
            };

            if (!$written || !is_file($destination)) {
                throw new StorageException('Failed to persist safely re-encoded image.');
            }

            @chmod($destination, 0640);
        } finally {
            umask($oldUmask);
            imagedestroy($imageResource);
        }

        return [
            'filename' => $safeFilename,
            'path'     => $destination,
            'width'    => $w,
            'height'   => $h,
            'mime'     => $detectedMime,
        ];
    }

    private static function processSvgUpload(string $tmpPath, string $destinationDir): array {
        $raw = file_get_contents($tmpPath);
        if (!is_string($raw) || strlen($raw) === 0) {
            throw new ValidationException('Empty SVG upload.');
        }

        $sanitized = SvgSanitizer::sanitize($raw);

        $resolvedDir = realpath($destinationDir);
        if ($resolvedDir === false || !is_dir($resolvedDir)) {
            throw new StorageException('Destination directory unavailable.');
        }

        $safeFilename = bin2hex(random_bytes(16)) . '.svg';
        $destination = $resolvedDir . DIRECTORY_SEPARATOR . $safeFilename;

        if (!str_starts_with($destination, $resolvedDir . DIRECTORY_SEPARATOR)) {
            throw new SecurityException('Path escaped jail.');
        }

        $oldUmask = umask(0077);
        try {
            $written = file_put_contents($destination, $sanitized, LOCK_EX);
            if ($written === false) {
                throw new StorageException('Failed to write sanitized SVG.');
            }
            @chmod($destination, 0640);
        } finally {
            umask($oldUmask);
        }

        return [
            'filename' => $safeFilename,
            'path'     => $destination,
            'width'    => 0,
            'height'   => 0,
            'mime'     => 'image/svg+xml',
        ];
    }

    /**
     * Pre-flight memory headroom assertion for image manipulation.
     */
    private static function assertMemoryPreflight(int $w, int $h): void {
        $neededBytes = ($w * $h * 4 * 1.5) + (10 * 1024 * 1024); // RGBA * 1.5 overhead + 10MB margin
        $limitStr = ini_get('memory_limit');
        if ($limitStr === false || $limitStr === '' || $limitStr === '-1') {
            return; // Unlimited memory
        }

        $limitBytes = self::parseMemoryLimit((string)$limitStr);
        $usedBytes = memory_get_usage(true);
        $headroom = $limitBytes - $usedBytes;

        if ($neededBytes > $headroom) {
            throw new ValidationException(sprintf(
                'Image dimensions (%dx%d) require more memory than available headroom.',
                $w,
                $h
            ));
        }
    }

    private static function parseMemoryLimit(string $val): int {
        $val = trim($val);
        $last = strtolower(substr($val, -1));
        $bytes = (int)$val;
        switch ($last) {
            case 'g': $bytes *= 1024 * 1024 * 1024; break;
            case 'm': $bytes *= 1024 * 1024; break;
            case 'k': $bytes *= 1024; break;
        }
        return $bytes;
    }
}
