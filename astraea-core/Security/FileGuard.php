<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Security;

use Astraea\Exceptions\AppException;
use Astraea\Exceptions\ValidationException;
use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\StorageException;
use Throwable;

/**
 * AstraeaOS Ingress File Security & Upload Guard.
 *
 * Implements:
 * - VGT Pattern 1.5.A: Typed Exception Hierarchy (ValidationException, SecurityException, StorageException).
 * - VGT Pattern 1.5.B: Measured filesize() pre-flight check on actual temp file before processing.
 * - VGT Pattern 1.5.D: MIME cross-check against IMAGETYPE_* integer constants (anti-polyglot).
 * - VGT Pattern 1.5.E: Path Jail validation using realpath() + post-construction str_starts_with().
 * - Section 3.2: Image Memory Pre-Flight calculation to eliminate pixel-flood DoS vectors.
 * - Anti-tampering: Double-extension inspection, null-byte/traversal prevention, SVG XXE/XSS sanitization.
 *
 * @package Astraea\Security
 */
final class FileGuard {

    public const DEFAULT_MAX_BYTES = 52428800; // 50 MB

    private const FORBIDDEN_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phps', 'phar',
        'exe', 'bat', 'cmd', 'sh', 'bash', 'bin', 'cgi', 'pl', 'py', 'pyw',
        'msi', 'com', 'scr', 'vbs', 'wsf', 'htaccess', 'user.ini'
    ];

    /**
     * Attach FileGuard filters to WordPress upload pipeline.
     */
    public static function init(): void {
        add_filter('wp_handle_upload_prefilter', [self::class, 'inspectUpload'], 1);
        add_filter('wp_check_filetype_and_ext', [self::class, 'verifyMimeAndExtension'], 10, 4);
    }

    /**
     * Inspect uploaded file before it is processed by WordPress.
     * Adheres to VGT Pattern 1.5.A catch-block dispatch.
     *
     * @param array<string, mixed> $file Element from $_FILES.
     * @return array<string, mixed>
     */
    public static function inspectUpload(array $file): array {
        if (!isset($file['name'], $file['tmp_name']) || !empty($file['error'])) {
            return $file;
        }

        try {
            self::validateUploadFile($file);
        } catch (ValidationException $e) {
            SecurityEventManager::recordOnce(SecurityEventManager::SEVERITY_WARNING, 'FileGuard', 'upload_validation_rejected', 'An upload was rejected by boundary validation.', ['exception' => get_class($e)], 10);
            $file['error'] = $e->getMessage();
            return $file;
        } catch (SecurityException $e) {
            SecurityEventManager::recordOnce(SecurityEventManager::SEVERITY_WARNING, 'FileGuard', 'upload_security_rejected', 'An upload was rejected by Astraea file security policy.', ['exception' => get_class($e)], 10);
            error_log('[SEC] ' . $e->getMessage());
            $file['error'] = 'Request rejected for security reasons.';
            return $file;
        } catch (StorageException $e) {
            SecurityEventManager::recordOnce(SecurityEventManager::SEVERITY_WARNING, 'FileGuard', 'upload_storage_fault', 'Upload storage processing could not complete.', ['exception' => get_class($e)], 10);
            error_log('[STORAGE] ' . $e->getMessage());
            $file['error'] = 'Upload storage processing error.';
            return $file;
        } catch (Throwable $e) {
            error_log('[SYS] File validation unhandled exception: ' . $e->getMessage());
            $file['error'] = 'Upload validation internal error.';
            return $file;
        }

        return $file;
    }

    /**
     * Compatibility alias for inspectUpload.
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public static function validateUpload(array $file): array {
        return self::inspectUpload($file);
    }

    /**
     * Validate an upload file structure against VGT security invariants.
     *
     * @param array<string, mixed> $file Element from $_FILES.
     * @param int $maxBytes Maximum allowed bytes.
     * @throws ValidationException On boundary violations.
     * @throws SecurityException On adversarial patterns (traversal, polyglot, executable code).
     */
    public static function validateUploadFile(array $file, int $maxBytes = self::DEFAULT_MAX_BYTES): void {
        if (!isset($file['name'], $file['tmp_name'])) {
            throw new ValidationException('Malformed upload payload structure.');
        }

        $filename = (string) $file['name'];
        $tmpPath  = (string) $file['tmp_name'];

        // VGT PATTERN 1.5.B — File Size Validation on actual temp file
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmpPath)) {
            throw new SecurityException('Upload path is not an authenticated HTTP upload.');
        }

        $realSize = filesize($tmpPath);
        if ($realSize === false || $realSize === 0 || $realSize > $maxBytes) {
            throw new ValidationException('Size boundary violation.');
        }

        // 1. Path traversal & null-byte check
        if (str_contains($filename, '..') || str_contains($filename, "\0") || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new SecurityException('Path traversal detected in upload filename.');
        }

        // 2. Double extension inspection (e.g. payload.php.png)
        $parts = explode('.', strtolower($filename));
        if (count($parts) > 2) {
            $subParts = array_slice($parts, 0, -1);
            foreach ($subParts as $subPart) {
                if (in_array($subPart, self::FORBIDDEN_EXTENSIONS, true)) {
                    throw new SecurityException(sprintf('Dangerous multi-extension detected: prohibited executable extension ".%s".', $subPart));
                }
            }
        }

        // 3. Final extension check
        $ext = end($parts);
        if (in_array($ext, self::FORBIDDEN_EXTENSIONS, true)) {
            throw new SecurityException(sprintf('Executable extension ".%s" is forbidden.', $ext));
        }

        // 4. Server-side MIME verification using fileinfo magic bytes
        $detectedMime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $finfoResult = finfo_file($finfo, $tmpPath);
                finfo_close($finfo);
                if (is_string($finfoResult)) {
                    $detectedMime = $finfoResult;
                }
            }
        }

        // Reject PHP scripts masquerading as other types
        if (str_starts_with($detectedMime, 'text/x-php') || $detectedMime === 'application/x-httpd-php') {
            throw new SecurityException('Malicious content: file contains PHP executable code.');
        }

        $expectedImageMime = match ($ext) {
            'jpg', 'jpeg', 'jpe' => 'image/jpeg',
            'png'                => 'image/png',
            'gif'                => 'image/gif',
            'webp'               => 'image/webp',
            default              => null,
        };
        if ($expectedImageMime !== null && $detectedMime !== $expectedImageMime) {
            throw new SecurityException('Image extension and MIME validation failed.');
        }

        // VGT PATTERN 1.5.D — MIME Cross-Check for images
        self::assertImageMimeTypeIntegrity($tmpPath, $detectedMime);

        // VGT Section 3.2 — Image Memory Pre-Flight
        self::assertImageMemorySafe($tmpPath);

        // 5. SVG content sanitization
        if ($ext === 'svg') {
            $svgContent = file_get_contents($tmpPath);
            if (is_string($svgContent) && self::containsSvgThreats($svgContent)) {
                throw new SecurityException('SVG rejected: contains dangerous XML entity, script, or event handler.');
            }
        }
    }

    /**
     * VGT PATTERN 1.5.D — MIME Cross-Check against IMAGETYPE_* integer constants.
     *
     * @param string $tmpPath Path to temporary file.
     * @param string $detectedMime MIME type detected by finfo.
     * @throws SecurityException On polyglot vector or MIME type mismatch.
     */
    public static function assertImageMimeTypeIntegrity(string $tmpPath, string $detectedMime): void {
        $expectedType = match($detectedMime) {
            'image/jpeg' => IMAGETYPE_JPEG,
            'image/png'  => IMAGETYPE_PNG,
            'image/webp' => IMAGETYPE_WEBP,
            'image/gif'  => IMAGETYPE_GIF,
            default      => null,
        };

        if ($expectedType !== null) {
            $imageInfo = @getimagesize($tmpPath);
            if ($imageInfo === false || $imageInfo[2] !== $expectedType) {
                throw new SecurityException('MIME/type mismatch. Polyglot vector blocked.');
            }
        }
    }

    /**
     * VGT Section 3.2 — Image Memory Pre-Flight Check.
     * Prevents pixel-flood decompression Denial of Service attacks.
     *
     * @param string $tmpPath Path to image file.
     * @throws SecurityException If decompression memory exceeds available buffer.
     */
    public static function assertImageMemorySafe(string $tmpPath): void {
        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false) {
            return;
        }

        $width  = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);

        if ($width <= 0 || $height <= 0) {
            return;
        }

        // Memory footprint: ($w * $h * 4 * 1.5) + 10MB
        $requiredBytes = (int) (($width * $height * 4 * 1.5) + (10 * 1024 * 1024));

        $memoryLimitStr = ini_get('memory_limit');
        $memoryLimit = self::parseMemoryLimit($memoryLimitStr);

        if ($memoryLimit > 0) {
            $usedMemory = memory_get_usage(true);
            $availableMemory = $memoryLimit - $usedMemory;

            if ($requiredBytes > $availableMemory) {
                throw new SecurityException('Image decompression memory pre-flight exceeded.');
            }
        }
    }

    /**
     * VGT PATTERN 1.5.E — Path Jail Enforcer.
     * Validates that target path resides strictly within the designated jail directory.
     *
     * @param string $input Base directory.
     * @param string $filename Target filename to resolve.
     * @return string Fully resolved jailed destination path.
     * @throws SecurityException If path escapes jail or base directory is invalid.
     */
    public static function assertWithinJail(string $input, string $filename): string {
        $resolvedDir = realpath($input);
        if ($resolvedDir === false || !is_dir($resolvedDir)) {
            throw new SecurityException('Path jail base invalid.');
        }

        $destination = $resolvedDir . DIRECTORY_SEPARATOR . $filename;
        $canonical = self::canonicalizePath($destination);

        if (!str_starts_with($canonical, $resolvedDir . DIRECTORY_SEPARATOR) && $canonical !== $resolvedDir) {
            throw new SecurityException('Path escaped jail.');
        }

        if (!str_starts_with($destination, $resolvedDir . DIRECTORY_SEPARATOR)) {
            throw new SecurityException('Path escaped jail.');
        }

        return $canonical;
    }

    /**
     * Canonicalize path removing relative dot segments.
     */
    public static function canonicalizePath(string $path): string {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $parts = explode(DIRECTORY_SEPARATOR, $normalized);
        $safe = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($safe);
            } else {
                $safe[] = $part;
            }
        }

        $isWindowsDrive = (DIRECTORY_SEPARATOR === '\\' && preg_match('/^[a-zA-Z]:$/', $parts[0] ?? ''));
        $prefix = $isWindowsDrive ? '' : DIRECTORY_SEPARATOR;

        return $prefix . implode(DIRECTORY_SEPARATOR, $safe);
    }

    /**
     * Cross-verify extension and detected MIME type.
     *
     * @param array<string, mixed> $data Extracted filetype data.
     * @param string $file Absolute path.
     * @param string $filename Original filename.
     * @param string[]|null $mimes Allowed mimes.
     * @return array<string, mixed>
     */
    public static function verifyMimeAndExtension(array $data, string $file, string $filename, ?array $mimes): array {
        $parts = explode('.', strtolower($filename));
        $ext = end($parts);

        if (in_array($ext, self::FORBIDDEN_EXTENSIONS, true)) {
            return ['ext' => false, 'type' => false, 'proper_filename' => false];
        }

        return $data;
    }

    /**
     * Check if SVG content contains XSS or XXE injection vectors.
     */
    public static function containsSvgThreats(string $content): bool {
        $patterns = [
            '/<!ENTITY/i',
            '/<!DOCTYPE[^>]*\[/i',
            '/<script/i',
            '/javascript\s*:/i',
            '/on\w+\s*=/i', // onload, onerror, onclick, etc.
            '/<foreignObject/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse PHP memory_limit string to integer bytes.
     */
    private static function parseMemoryLimit(string $val): int {
        $val = trim($val);
        if ($val === '' || $val === '-1') {
            return -1;
        }

        $last = strtolower(substr($val, -1));
        $bytes = (int) $val;

        switch ($last) {
            case 'g':
                $bytes *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $bytes *= 1024 * 1024;
                break;
            case 'k':
                $bytes *= 1024;
                break;
        }

        return $bytes;
    }
}
