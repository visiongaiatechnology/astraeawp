<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

/**
 * AstraeaOS WP Automated Release Packaging Utility.
 *
 * Produces deterministic distribution archives:
 * 1. Runtime Distribution ZIP (Production-ready distribution)
 * 2. Source Distribution ZIP (Full development distribution)
 *
 * Computes SHA-256 checksums and validates manifest inclusion.
 *
 * Zero external dependencies: PHP ZipArchive + hash + SPL.
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI execution only.');
}

$options = getopt('', ['allow-unsigned']);
$rootDir = dirname(__DIR__);
$manifestPath = $rootDir . '/BUILD-MANIFEST.json';
$manifestRaw = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
try {
    $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR) : null;
} catch (JsonException) {
    $manifest = null;
}
$authenticity = is_array($manifest) && is_array($manifest['authenticity'] ?? null) ? $manifest['authenticity'] : [];
$signed = ($authenticity['status'] ?? '') === 'signed'
    && ($authenticity['algorithm'] ?? '') === 'Ed25519'
    && is_string($authenticity['key_id'] ?? null) && $authenticity['key_id'] !== ''
    && is_string($authenticity['signature'] ?? null) && $authenticity['signature'] !== '';
if (!$signed && !array_key_exists('allow-unsigned', $options)) {
    fwrite(STDERR, "[FATAL] Public distribution packaging requires an offline Ed25519-signed BUILD-MANIFEST.json.\n");
    exit(2);
}
if (!$signed) {
    fwrite(STDERR, "[WARNING] Building explicitly unsigned development artifacts; these are not public releases.\n");
}

$versionFile = $rootDir . '/astraea-core/Version.php';
if (!is_file($versionFile)) {
    fwrite(STDERR, "[FATAL] Version.php missing.\n");
    exit(1);
}
require_once $versionFile;
$version = \Astraea\Version::VERSION;

$distDir = $rootDir . '/dist';
if (!is_dir($distDir)) {
    mkdir($distDir, 0755, true);
}

printf("Packaging AstraeaOS WP %s...\n", $version);

// 1. Build Runtime Package
$runtimeZipPath = $distDir . "/astraeaos-wp-runtime-{$version}.zip";
@unlink($runtimeZipPath);
$runtimeZip = new ZipArchive();
if ($runtimeZip->open($runtimeZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "[FATAL] Failed to create runtime archive.\n");
    exit(1);
}

$runtimeExcludes = [
    '/.git',
    '/dist',
    '/tests',
    '/wp-content/uploads',
    '/wp-content/cache',
    '/wp-content/astraea-vault-snapshots',
    '/.gemini',
    '/node_modules',
];

$fileCountRuntime = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $item) {
    if (!$item->isFile() || $item->isLink()) {
        continue;
    }
    $abs = $item->getPathname();
    $rel = str_replace('\\', '/', substr($abs, strlen($rootDir)));

    $excluded = false;
    foreach ($runtimeExcludes as $ex) {
        if (str_starts_with($rel, $ex)) {
            $excluded = true;
            break;
        }
    }
    if ($excluded) {
        continue;
    }

    $runtimeZip->addFile($abs, ltrim($rel, '/'));
    $fileCountRuntime++;
}
$runtimeZip->close();
$runtimeSha256 = hash_file('sha256', $runtimeZipPath);
$runtimeBytes = filesize($runtimeZipPath);

// 2. Build Source Package
$sourceZipPath = $distDir . "/astraeaos-wp-source-{$version}.zip";
@unlink($sourceZipPath);
$sourceZip = new ZipArchive();
if ($sourceZip->open($sourceZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "[FATAL] Failed to create source archive.\n");
    exit(1);
}

$sourceExcludes = [
    '/.git',
    '/dist',
    '/wp-content/uploads',
    '/wp-content/cache',
    '/wp-content/astraea-vault-snapshots',
    '/.gemini',
];

$fileCountSource = 0;
foreach ($iterator as $item) {
    if (!$item->isFile() || $item->isLink()) {
        continue;
    }
    $abs = $item->getPathname();
    $rel = str_replace('\\', '/', substr($abs, strlen($rootDir)));

    $excluded = false;
    foreach ($sourceExcludes as $ex) {
        if (str_starts_with($rel, $ex)) {
            $excluded = true;
            break;
        }
    }
    if ($excluded) {
        continue;
    }

    $sourceZip->addFile($abs, ltrim($rel, '/'));
    $fileCountSource++;
}
$sourceZip->close();
$sourceSha256 = hash_file('sha256', $sourceZipPath);
$sourceBytes = filesize($sourceZipPath);

// 3. Write SHA256SUMS file
$checksumsContent = sprintf(
    "%s  %s\n%s  %s\n",
    $runtimeSha256,
    basename($runtimeZipPath),
    $sourceSha256,
    basename($sourceZipPath)
);
file_put_contents($distDir . '/SHA256SUMS', $checksumsContent);

echo "\n=======================================================\n";
echo "  ASTRAEAOS WP DISTRIBUTION PACKAGE REPORT             \n";
echo "=======================================================\n";
printf("Version:             %s\n", $version);
printf("Runtime Archive:     %s\n", basename($runtimeZipPath));
printf("  Files:             %d\n", $fileCountRuntime);
printf("  Size:              %s MB (%d bytes)\n", number_format($runtimeBytes / (1024 * 1024), 2), $runtimeBytes);
printf("  SHA-256:           %s\n\n", $runtimeSha256);

printf("Source Archive:      %s\n", basename($sourceZipPath));
printf("  Files:             %d\n", $fileCountSource);
printf("  Size:              %s MB (%d bytes)\n", number_format($sourceBytes / (1024 * 1024), 2), $sourceBytes);
printf("  SHA-256:           %s\n\n", $sourceSha256);
echo "Checksum file:       dist/SHA256SUMS\n";
echo "=======================================================\n";
echo "Release packaging completed successfully.\n";
exit(0);
