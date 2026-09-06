<?php
declare(strict_types=1);

if (!defined('ABSPATH')) exit('VGT_ACCESS_DENIED');

final class VIS_Dashboard_Core {
    private static bool $booted = false;
    private string $page_hook = '';

    public function __construct() {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $this->load_dependencies();

        add_action('admin_menu', [$this, 'register_menu_matrix'], 9);
        add_action('admin_init', ['VIS_Dashboard_Settings', 'process_mutations']);
        add_action('admin_enqueue_scripts', [$this, 'inject_assets']);
        add_action('admin_notices', [$this, 'display_setup_wizard_notice']);
        add_action('admin_notices', [$this, 'display_admin_whitelist_notice']);

        // Secure Genesis is authoritative for first-install security compilation.
        // Existing upgraded installations without a Genesis state keep the legacy behavior.
        $genesisState = (string)get_option('astraea_install_state', '');
        if (!get_option('vgt_setup_wizard_completed') && ($genesisState === '' || $genesisState === 'READY')) {
            update_option('vgt_setup_wizard_completed', 1);
        }
        
        VIS_Dashboard_Ajax::mount_endpoints();
        VIS_Sentinel_Export::mount();

        // VGT KERNEL: Initialisierung & Boot des autonomen Scanner-Kerns
        $scanner_path = defined('VIS_PATH') ? VIS_PATH . 'includes/scanner/class-vis-scanner-engine.php' : '';
        if ($scanner_path && file_exists($scanner_path)) {
            if (!class_exists('VIS_Scanner_Engine_Omega')) {
                require_once $scanner_path;
            }
            if (class_exists('VIS_Scanner_Engine_Omega')) {
                new VIS_Scanner_Engine_Omega();
            }
        }
    }

    public function display_setup_wizard_notice(): void {
        // In AstraeaOS, setup wizard is pre-completed.
        return;
    }

    public function display_admin_whitelist_notice(): void {
        if (!current_user_can('manage_options') || wp_doing_ajax()) return;

        $ip = $this->resolve_admin_ip();
        if ($ip === '') return;

        $config = get_option('vis_config', []);
        $config = is_array($config) ? $config : [];

        // Auto-whitelist current authenticated admin so they are never blocked or alarmed
        $changed = false;
        if (!$this->whitelist_contains((string)($config['aegis_whitelist_ips'] ?? ''), $ip)) {
            $currentAegis = trim((string)($config['aegis_whitelist_ips'] ?? ''));
            $config['aegis_whitelist_ips'] = $currentAegis !== '' ? $currentAegis . "\n" . $ip : $ip;
            $changed = true;
        }
        if (!$this->whitelist_contains((string)($config['prometheus_whitelist_ips'] ?? ''), $ip)) {
            $currentProm = trim((string)($config['prometheus_whitelist_ips'] ?? ''));
            $config['prometheus_whitelist_ips'] = $currentProm !== '' ? $currentProm . "\n" . $ip : $ip;
            $changed = true;
        }
        if ($changed) {
            update_option('vis_config', $config);
        }
        return;
    }

    private function resolve_admin_ip(): string {
        $candidate = class_exists('VIS_Security') && method_exists('VIS_Security', 'client_ip')
            ? VIS_Security::client_ip()
            : (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_IP) !== false ? $candidate : '';
    }

    private function whitelist_contains(string $raw, string $ip): bool {
        foreach (preg_split('/\R/u', $raw) ?: [] as $entry) {
            if (is_string($entry) && hash_equals(trim($entry), $ip)) return true;
        }
        return false;
    }

    private function load_dependencies(): void {
        $dir = plugin_dir_path(__FILE__);
        $dependencies = [
            'VIS_Dashboard_Assets'   => 'class-vis-dashboard-assets.php',
            'VIS_Dashboard_Settings' => 'class-vis-dashboard-settings.php',
            'VIS_Dashboard_Ajax'     => 'class-vis-dashboard-ajax.php',
            'VIS_Sentinel_Export'    => 'class-vis-sentinel-export.php'
        ];

        foreach ($dependencies as $class => $file) {
            if (!class_exists($class) && is_readable($dir . $file)) {
                require_once $dir . $file;
            }
        }
    }

    public function register_menu_matrix(): void {
        $this->page_hook = add_menu_page(
            'VGT Suite', 
            'VGT Suite', 
            'manage_options', 
            'vgt-suite', 
            '__return_empty_string', 
            VIS_SENTINEL_ICON, 
            99
        );

        add_submenu_page(
            'vgt-suite',
            'GeDefense WP',
            'GeDefense WP',
            'manage_options',
            'vgt-suite',
            [new VIS_Dashboard_View(), 'render']
        );

        add_submenu_page(
            'vgt-suite',
            'ThroneGuard',
            'ThroneGuard',
            'manage_options',
            'vgt-throneguard',
            static function(): void {
                $_GET['tab'] = 'throneguard';
                (new VIS_Dashboard_View())->render();
            }
        );

        add_submenu_page(
            'vgt-suite',
            'LoginPager',
            'LoginPager',
            'manage_options',
            'vgt-loginpager',
            static function(): void {
                $_GET['tab'] = 'loginpager';
                (new VIS_Dashboard_View())->render();
            }
        );
    }

    public function inject_assets(string $current_hook): void {
        $allowed_pages = ['vgt-suite', 'vgt-throneguard', 'vgt-loginpager'];
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (!in_array($page, $allowed_pages, true) && $current_hook !== $this->page_hook) return;
        VIS_Dashboard_Assets::enqueue();
    }
}
