<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

/**
 * AstraeaOS WP deterministic manifest builder.
 *
 * Zero dependencies: PHP SPL + hash + optional libsodium Ed25519.
 * The private signing key is supplied offline and is never written into the
 * release. Without it, the manifest explicitly records UNSIGNED status.
 *
 * Usage:
 *   php tools/build-release.php [--root=/path/to/release] [--verify-only]
 *   php tools/build-release.php --root=/path --signing-key=/secure/ed25519.key
 */

$options = getopt('', ['root::', 'verify-only', 'signing-key::']);
$sourceRoot = dirname(__DIR__);
$rootDir = isset($options['root']) && is_string($options['root']) ? realpath($options['root']) : realpath($sourceRoot);
if (!is_string($rootDir) || !is_dir($rootDir)) {
    fwrite(STDERR, "[FATAL] Invalid release root.\n");
    exit(1);
}

$versionFile = $rootDir . '/astraea-core/Version.php';
if (!is_file($versionFile)) {
    fwrite(STDERR, "[FATAL] Astraea Version.php is missing.\n");
    exit(1);
}
require_once $versionFile;
$version = \Astraea\Version::VERSION;

if (PHP_VERSION_ID < 80300 || !extension_loaded('json') || !extension_loaded('hash')) {
    fwrite(STDERR, "[FATAL] Build runtime requires PHP 8.3+, json and hash.\n");
    exit(1);
}

$verifyOnly = array_key_exists('verify-only', $options);
if ($verifyOnly) {
    $okCore = verifyCoreManifest($rootDir);
    $okRoot = verifyRootManifest($rootDir);
    exit(($okCore && $okRoot) ? 0 : 2);
}

$core = buildCoreManifest($rootDir, $version);
writeJsonAtomic($rootDir . '/astraea-core/BUILD-MANIFEST.json', $core);
$root = buildRootManifest($rootDir, $version);

$signingKeyPath = isset($options['signing-key']) && is_string($options['signing-key']) ? $options['signing-key'] : '';
$root['authenticity'] = signManifest($root, $signingKeyPath);
writeJsonAtomic($rootDir . '/BUILD-MANIFEST.json', $root);

if (!verifyCoreManifest($rootDir) || !verifyRootManifest($rootDir)) {
    fwrite(STDERR, "[FATAL] Post-build manifest verification failed.\n");
    exit(3);
}

printf("AstraeaOS WP %s manifests generated and verified. Authenticity: %s\n", $version, strtoupper((string)$root['authenticity']['status']));
exit(0);

/** @return array<string,mixed> */
function buildCoreManifest(string $rootDir, string $version): array
{
    $coreDir = $rootDir . '/astraea-core';
    $files = [];
    foreach (scanFiles($coreDir, static fn(string $rel): bool => $rel === '/BUILD-MANIFEST.json') as $rel => $abs) {
        $files[ltrim($rel, '/')] = hash_file('sha256', $abs);
    }
    ksort($files, SORT_STRING);
    $ctx = hash_init('sha256');
    foreach ($files as $path => $hash) {
        hash_update($ctx, $path . ':' . $hash . "\n");
    }
    return [
        'project' => 'AstraeaOS WP Core',
        'version' => $version,
        'integrity' => 'SHA-256 local runtime integrity; authenticity is verified separately by the signed root manifest when configured.',
        'root_hash' => hash_final($ctx),
        'file_count' => count($files),
        'files' => $files,
    ];
}

/** @return array<string,mixed> */
function buildRootManifest(string $rootDir, string $version): array
{
    $files = [];
    $ctx = hash_init('sha256');
    foreach (scanFiles($rootDir, static fn(string $rel): bool => $rel === '/BUILD-MANIFEST.json' || str_starts_with($rel, '/dist/')) as $rel => $abs) {
        $hash = hash_file('sha256', $abs);
        $size = filesize($abs);
        $files[ltrim($rel, '/')] = ['sha256' => $hash, 'size_bytes' => is_int($size) ? $size : 0];
    }
    ksort($files, SORT_STRING);
    foreach ($files as $path => $meta) {
        hash_update($ctx, $path . ':' . $meta['sha256'] . ':' . $meta['size_bytes'] . "\n");
    }
    return [
        'project' => 'AstraeaOS WP',
        'edition' => 'Core Distribution with GeDefense, Astraea Vault, VLP Light and Astraea Mail Gateway',
        'version' => $version,
        'built_at' => gmdate('c'),
        'requirements' => [
            'php_min' => '8.3',
            'mysql_min' => '8.0',
            'mariadb_min' => '10.11',
            'mandatory_extensions' => ['json', 'hash', 'sodium', 'openssl', 'mbstring', 'intl', 'dom'],
        ],
        'security' => [
            'integrity_algorithm' => 'SHA-256',
            'release_signature_algorithm' => 'Ed25519 when an offline signing key is supplied',
            'corpus_sha256' => hash_final($ctx),
        ],
        'total_files' => count($files),
        'files' => $files,
    ];
}

/** @return array<string,string> */
function signManifest(array $manifest, string $keyPath): array
{
    if ($keyPath === '') {
        return ['status' => 'unsigned', 'algorithm' => 'none', 'key_id' => '', 'signature' => '', 'note' => 'Local SHA-256 integrity only. No VGT offline signing key was supplied.'];
    }
    if (!extension_loaded('sodium')) {
        throw new RuntimeException('libsodium is required for Ed25519 signing.');
    }
    $resolved = realpath($keyPath);
    if (!is_string($resolved) || !is_file($resolved) || !is_readable($resolved)) {
        throw new RuntimeException('Signing key file is not readable.');
    }
    $raw = file_get_contents($resolved);
    if (!is_string($raw)) {
        throw new RuntimeException('Unable to read signing key.');
    }
    $secret = normalizeSigningSecret(trim($raw));
    $public = sodium_crypto_sign_publickey_from_secretkey($secret);
    $payload = canonicalPayload($manifest);
    $signature = sodium_crypto_sign_detached($payload, $secret);
    sodium_memzero($secret);
    return [
        'status' => 'signed',
        'algorithm' => 'Ed25519',
        'key_id' => substr(hash('sha256', $public), 0, 16),
        'signature' => base64_encode($signature),
        'note' => 'Verify against the trusted Astraea release public key embedded/provisioned independently of this manifest.',
    ];
}

function normalizeSigningSecret(string $value): string
{
    if (strlen($value) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        return $value;
    }
    if (strlen($value) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES * 2 && ctype_xdigit($value)) {
        $decoded = hex2bin($value);
        if (is_string($decoded)) { return $decoded; }
    }
    $decoded = base64_decode($value, true);
    if (is_string($decoded) && strlen($decoded) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        return $decoded;
    }
    throw new RuntimeException('Signing key must be a raw, hexadecimal, or base64 Ed25519 secret key.');
}

/** @param array<string,mixed> $manifest */
function canonicalPayload(array $manifest): string
{
    $project = (string)($manifest['project'] ?? '');
    $version = (string)($manifest['version'] ?? '');
    $corpus = is_array($manifest['security'] ?? null) ? (string)($manifest['security']['corpus_sha256'] ?? '') : '';
    $count = (int)($manifest['total_files'] ?? 0);
    return "ASTRAEA-RELEASE-V1\nproject={$project}\nversion={$version}\ncorpus_sha256={$corpus}\ntotal_files={$count}\n";
}

/** @return array<string,string> rel path with leading slash => absolute path */
function scanFiles(string $base, callable $exclude): array
{
    $resolved = realpath($base);
    if (!is_string($resolved)) { throw new RuntimeException('Scan root does not exist.'); }
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        if (!$item->isFile() || $item->isLink()) { continue; }
        $abs = $item->getPathname();
        $rel = '/' . ltrim(str_replace('\\', '/', substr($abs, strlen($resolved))), '/');
        if ($exclude($rel)) { continue; }
        $files[$rel] = $abs;
    }
    ksort($files, SORT_STRING);
    return $files;
}

function writeJsonAtomic(string $path, array $payload): void
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to write manifest temporary file.');
    }
    @chmod($tmp, 0600);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to atomically publish manifest.');
    }
}

function verifyCoreManifest(string $rootDir): bool
{
    $path = $rootDir . '/astraea-core/BUILD-MANIFEST.json';
    $manifest = readJson($path);
    if (!isset($manifest['files']) || !is_array($manifest['files'])) { return false; }
    $actual = scanFiles($rootDir . '/astraea-core', static fn(string $rel): bool => $rel === '/BUILD-MANIFEST.json');
    $expectedPaths = array_keys($manifest['files']);
    $actualPaths = array_map(static fn(string $rel): string => ltrim($rel, '/'), array_keys($actual));
    sort($expectedPaths, SORT_STRING); sort($actualPaths, SORT_STRING);
    if ($expectedPaths !== $actualPaths) { return false; }
    $ctx = hash_init('sha256');
    foreach ($manifest['files'] as $rel => $expected) {
        if (!is_string($rel) || !is_string($expected)) { return false; }
        $abs = $rootDir . '/astraea-core/' . $rel;
        $actualHash = hash_file('sha256', $abs);
        if (!is_string($actualHash) || !hash_equals($expected, $actualHash)) { return false; }
        hash_update($ctx, $rel . ':' . $expected . "\n");
    }
    return hash_equals((string)($manifest['root_hash'] ?? ''), hash_final($ctx));
}

function verifyRootManifest(string $rootDir): bool
{
    $path = $rootDir . '/BUILD-MANIFEST.json';
    $manifest = readJson($path);
    if (!isset($manifest['files']) || !is_array($manifest['files'])) { return false; }
    $actual = scanFiles($rootDir, static fn(string $rel): bool => $rel === '/BUILD-MANIFEST.json' || str_starts_with($rel, '/dist/'));
    $expectedPaths = array_keys($manifest['files']);
    $actualPaths = array_map(static fn(string $rel): string => ltrim($rel, '/'), array_keys($actual));
    sort($expectedPaths, SORT_STRING); sort($actualPaths, SORT_STRING);
    if ($expectedPaths !== $actualPaths) { return false; }
    $ctx = hash_init('sha256');
    foreach ($manifest['files'] as $rel => $meta) {
        if (!is_string($rel) || !is_array($meta) || !isset($meta['sha256'], $meta['size_bytes'])) { return false; }
        $abs = $rootDir . '/' . ltrim($rel, '/');
        if (!is_file($abs) || is_link($abs)) { return false; }
        $hash = hash_file('sha256', $abs);
        $size = filesize($abs);
        if (!is_string($hash) || !hash_equals((string)$meta['sha256'], $hash) || !is_int($size) || $size !== (int)$meta['size_bytes']) { return false; }
        hash_update($ctx, $rel . ':' . $meta['sha256'] . ':' . $meta['size_bytes'] . "\n");
    }
    return hash_equals((string)($manifest['security']['corpus_sha256'] ?? ''), hash_final($ctx));
}

/** @return array<string,mixed> */
function readJson(string $path): array
{
    $raw = file_get_contents($path);
    if (!is_string($raw)) { return []; }
    try { $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { return []; }
    return is_array($decoded) ? $decoded : [];
}
