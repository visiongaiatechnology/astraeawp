<?php
declare(strict_types=1);

/**
 * AstraeaOS WP Automated Test Runner.
 *
 * Executes Phase 1 Core Foundation test suites:
 * - Authentication & Argon2id Policy & Migration
 * - Cryptography Core & AEAD & AAD & Master Key
 * - Security-by-Default (Randomness, FileGuard, Logger)
 * - WordPress Compatibility & Pluggable Overrides
 * - GeDefense First-Party Integration
 */

// Define mock WordPress environment constants if running standalone CLI
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('ASTRAEA_KEYRING_FILE')) {
    $astraeaTestKeyring = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'astraea-test-keyring-' . getmypid() . '-' . bin2hex(random_bytes(6)) . '.json';
    define('ASTRAEA_KEYRING_FILE', $astraeaTestKeyring);
    register_shutdown_function(static function() use ($astraeaTestKeyring): void {
        if (is_file($astraeaTestKeyring)) @unlink($astraeaTestKeyring);
        $lock = $astraeaTestKeyring . '.lock';
        if (is_file($lock)) @unlink($lock);
    });
}
if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('AUTH_KEY')) {
    define('AUTH_KEY', '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');
}
if (!defined('AUTH_SALT')) {
    define('AUTH_SALT', '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');
}
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
}
if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
}
if (!defined('WPMU_PLUGIN_DIR')) {
    define('WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins');
}
$GLOBALS['wp_plugin_paths'] = [];

// Load WordPress Hook subsystem and Session Token classes
require_once ABSPATH . WPINC . '/plugin.php';
require_once ABSPATH . WPINC . '/class-wp-session-tokens.php';
require_once ABSPATH . WPINC . '/class-wp-user-meta-session-tokens.php';

// Stubs for CLI standalone test execution
if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string {
        return 'https://example.com/wp-admin/' . ltrim($path, '/');
    }
}
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string {
        return 'https://example.com/' . ltrim($path, '/');
    }
}
if (!function_exists('site_url')) {
    function site_url(string $path = ''): string {
        return 'https://example.com/' . ltrim($path, '/');
    }
}
if (!function_exists('content_url')) {
    function content_url(string $path = ''): string {
        return 'https://example.com/wp-content/' . ltrim($path, '/');
    }
}
if (!function_exists('esc_url')) {
    function esc_url(string $url): string {
        return $url;
    }
}
if (!function_exists('esc_html')) {
    function esc_html(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string {
        return strip_tags($text);
    }
}
if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax(): bool {
        return false;
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false {
        return json_encode($data, $options, $depth);
    }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title(string $title): string {
        return preg_replace('/[^a-z0-9_-]/', '', strtolower($title));
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string {
        $filtered = wp_strip_all_tags($str, false);
        return trim($filtered);
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }
}
if (!function_exists('wp_normalize_path')) {
    function wp_normalize_path(string $path): string {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('|(?<=.)/+|', '/', $path);
        if (':' === substr($path, 1, 1)) {
            $path = ucfirst($path);
        }
        return $path;
    }
}
if (!function_exists('size_format')) {
    function size_format(int $bytes): string {
        return round($bytes / 1048576, 2) . ' MB';
    }
}
if (!function_exists('is_admin')) {
    function is_admin(): bool {
        return false;
    }
}
if (!function_exists('wp_die')) {
    function wp_die(string $msg = ''): void {
        throw new \RuntimeException($msg);
    }
}
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string { return $text; }
}
if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string|int $action = -1): string { return substr(hash('sha256', 'test-nonce|' . (string)$action), 0, 10); }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string|int $action = -1, string $name = '_wpnonce', bool $referer = true, bool $display = true): string {
        $field = '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars(wp_create_nonce($action), ENT_QUOTES, 'UTF-8') . '">';
        if ($display) echo $field;
        return $field;
    }
}
if (!function_exists('checked')) {
    function checked(mixed $checked, mixed $current = true, bool $display = true): string { $r = ((string)$checked === (string)$current) ? ' checked="checked"' : ''; if ($display) echo $r; return $r; }
}
if (!function_exists('selected')) {
    function selected(mixed $selected, mixed $current = true, bool $display = true): string { $r = ((string)$selected === (string)$current) ? ' selected="selected"' : ''; if ($display) echo $r; return $r; }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg(mixed $key, mixed $value = null, mixed $url = null): string {
        if (is_array($key)) { $args = $key; $base = is_string($value) ? $value : ''; } else { $args = [(string)$key => $value]; $base = is_string($url) ? $url : ''; }
        $sep = str_contains($base, '?') ? '&' : '?';
        return $base . ($args ? $sep . http_build_query($args) : '');
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool {
        return true;
    }
}
if (!function_exists('wp_count_posts')) {
    function wp_count_posts(string $type = 'post'): object {
        return (object)['publish' => 4, 'draft' => 1];
    }
}
if (!function_exists('wp_count_comments')) {
    function wp_count_comments(): object {
        return (object)['approved' => 12, 'moderated' => 0];
    }
}
if (!function_exists('wp_count_attachments')) {
    function wp_count_attachments(): object {
        return (object)['image' => 8];
    }
}
if (!function_exists('get_posts')) {
    function get_posts(array $args = []): array {
        return [];
    }
}
if (!function_exists('get_the_title')) {
    function get_the_title($post = 0): string {
        return 'Sample Post';
    }
}
if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link(int $id = 0, string $context = 'display'): string {
        return admin_url('post.php?post=' . $id . '&action=edit');
    }
}
if (!function_exists('get_post_type_object')) {
    function get_post_type_object(string $post_type): ?object {
        return (object)['labels' => (object)['name' => ucfirst($post_type)]];
    }
}
if (!function_exists('get_option')) {
    $GLOBALS['_astraea_test_options'] = [];
    function get_option(string $option, mixed $default = false): mixed {
        return $GLOBALS['_astraea_test_options'][$option] ?? $default;
    }
    function update_option(string $option, mixed $value): bool {
        $GLOBALS['_astraea_test_options'][$option] = $value;
        return true;
    }
    function delete_option(string $option): bool {
        unset($GLOBALS['_astraea_test_options'][$option]);
        return true;
    }
}
if (!function_exists('get_user_meta')) {
    $GLOBALS['_astraea_test_usermeta'] = [];
    function get_user_meta(int $user_id, string $key = '', bool $single = false): mixed {
        $val = $GLOBALS['_astraea_test_usermeta'][$user_id][$key] ?? ($single ? '' : []);
        return $val;
    }
    function update_user_meta(int $user_id, string $key, mixed $value, mixed $prev_value = ''): bool {
        if (!empty($GLOBALS['_astraea_test_fail_update_user_meta'])) return false;
        if ($prev_value !== '' && (($GLOBALS['_astraea_test_usermeta'][$user_id][$key] ?? null) !== $prev_value)) return false;
        $GLOBALS['_astraea_test_usermeta'][$user_id][$key] = $value;
        return true;
    }
    function delete_user_meta(int $user_id, string $key, mixed $value = ''): bool {
        if (!empty($GLOBALS['_astraea_test_fail_delete_user_meta'])) return false;
        if ($value !== '' && (($GLOBALS['_astraea_test_usermeta'][$user_id][$key] ?? null) !== $value)) return false;
        unset($GLOBALS['_astraea_test_usermeta'][$user_id][$key]);
        return true;
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int {
        return 1;
    }
}
if (!function_exists('wp_get_session_token')) {
    function wp_get_session_token(): string {
        return 'astraea-test-session-token-0123456789abcdef';
    }
}
if (!function_exists('wp_cache_get')) {
    function wp_cache_get(string $key, string $group = ''): mixed {
        return false;
    }
    function wp_cache_set(string $key, mixed $data, string $group = '', int $expire = 0): bool {
        return true;
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir(?string $time = null, bool $create_dir = true, bool $refresh_cache = false): array {
        $base = WP_CONTENT_DIR . '/uploads';
        return [
            'path' => $base,
            'url' => 'https://example.com/wp-content/uploads',
            'subdir' => '',
            'basedir' => $base,
            'baseurl' => 'https://example.com/wp-content/uploads',
            'error' => false,
        ];
    }
}
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new class {
        public string $prefix = 'wp_';
        public function prepare(string $query, mixed ...$args): string {
            foreach ($args as $arg) {
                $replacement = is_int($arg) ? (string)$arg : "'" . str_replace("'", "''", (string)$arg) . "'";
                $query = preg_replace('/%[sd]/', $replacement, $query, 1) ?? $query;
            }
            return $query;
        }
        public function get_var(string $query): mixed {
            if (str_contains($query, 'GET_LOCK(') || str_contains($query, 'RELEASE_LOCK(')) return 1;
            return null;
        }
        public function db_version(): string {
            return '8.0.36';
        }
        public function insert(string $table, array $data, array $format = []): int|false {
            return 1;
        }
        public function update(string $table, array $data, array $where, array $format = [], array $where_format = []): int|false {
            return 1;
        }
        public function get_results(string $query, string $output = 'OBJECT'): array {
            return [];
        }
        public function get_row(string $query, string $output = 'OBJECT', int $y = 0): ?object {
            return null;
        }
    };
}


// Register Astraea autoloader
require_once dirname(__DIR__) . '/wp-includes/version.php';
require_once dirname(__DIR__) . '/astraea-core/Bootstrap/Autoloader.php';
\Astraea\Bootstrap\Autoloader::register(dirname(__DIR__) . '/astraea-core');

// Register Astraea Vault autoloader
if (file_exists(dirname(__DIR__) . '/astraea-core/Vault/src/Autoloader.php')) {
    require_once dirname(__DIR__) . '/astraea-core/Vault/src/Autoloader.php';
    \Astraea\Vault\Autoloader::register(dirname(__DIR__) . '/astraea-core/Vault/src');
}

// Load procedural crypto and pluggable overrides
require_once dirname(__DIR__) . '/astraea-core/Crypto/functions.php';
require_once dirname(__DIR__) . '/astraea-core/Auth/pluggable-overrides.php';

// Load test suites
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/AuthTest.php';
require_once __DIR__ . '/CryptoTest.php';
require_once __DIR__ . '/DatabaseTest.php';
require_once __DIR__ . '/SecurityTest.php';
require_once __DIR__ . '/CompatibilityTest.php';
require_once __DIR__ . '/GeDefenseTest.php';
require_once __DIR__ . '/AdminUITest.php';
require_once __DIR__ . '/VaultTest.php';
require_once __DIR__ . '/SessionTest.php';
require_once __DIR__ . '/PublicReleaseTest.php';
require_once __DIR__ . '/I18nTest.php';

use Astraea\Tests\TestCase;
use Astraea\Tests\AuthTest;
use Astraea\Tests\CryptoTest;
use Astraea\Tests\DatabaseTest;
use Astraea\Tests\SecurityTest;
use Astraea\Tests\CompatibilityTest;
use Astraea\Tests\GeDefenseTest;
use Astraea\Tests\AdminUITest;
use Astraea\Tests\VaultTest;
use Astraea\Tests\SessionTest;
use Astraea\Tests\PublicReleaseTest;
use Astraea\Tests\I18nTest;

echo "=======================================================\n";
echo "  ASTRAEAOS WP — PHASE 1, 2 & 3.5 TEST SUITE RUNNER    \n";
echo "  Runtime: PHP " . PHP_VERSION . " (" . (PHP_INT_SIZE === 8 ? '64-bit' : '32-bit') . ")\n";
echo "=======================================================\n\n";

$startTime = microtime(true);

AuthTest::run();
CryptoTest::run();
DatabaseTest::run();
SecurityTest::run();
CompatibilityTest::run();
GeDefenseTest::run();
AdminUITest::run();
VaultTest::run();
SessionTest::run();
PublicReleaseTest::run();
I18nTest::run();


$duration = (microtime(true) - $startTime) * 1000.0;
$stats = TestCase::getStats();

echo "\n=======================================================\n";
echo sprintf("  Total Assertions: %d\n", $stats['assertions']);
echo sprintf("  Failures:         %d\n", $stats['failures']);
echo sprintf("  Execution Time:   %.2f ms\n", $duration);
echo "=======================================================\n";

if ($stats['failures'] > 0) {
    echo "\nFAILED ASSERTIONS:\n";
    foreach ($stats['messages'] as $msg) {
        echo "  - {$msg}\n";
    }
    exit(1);
}

echo "\nSUCCESS: All AstraeaOS WP Core Foundation tests passed!\n";
exit(0);
