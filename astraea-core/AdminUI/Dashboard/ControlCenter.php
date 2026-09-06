<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\AdminUI\Dashboard;

use Astraea\Security\Baseline;
use Astraea\Security\FileGuard;
use Astraea\Security\Headers;
use Astraea\Performance\LegacyPruner;
use Astraea\Diagnostics\SecurityProbeManager;
use Astraea\Diagnostics\HealthStatus;
use Astraea\Auth\SessionManager;
use Astraea\Auth\StepUpAuthService;
use Astraea\Security\SecurityEventManager;

/**
 * AstraeaOS Control Center Dashboard.
 *
 * Modern command center replacing the legacy WordPress dashboard with:
 * - Real-time System Telemetry (PHP, DB, Memory, Server)
 * - GeDefense Multi-Layer Security HUD (Cerberus, Zeus, Aegis, Hades)
 * - Performance Metrics (OpCache, Memory Headroom, Pruned Surfaces)
 * - Content & Activity Pulse (Posts, Pages, Media, Comments)
 * - Quick Operations Panel
 *
 * @package Astraea\AdminUI\Dashboard
 */
final class ControlCenter {

    /**
     * Initialize Dashboard transformation hooks.
     */
    public static function init(): void {
        // Register top-level dashboard hook
        add_action('welcome_panel', [self::class, 'renderControlCenter']);
        add_action('admin_menu', [self::class, 'registerAdminPages']);
    }

    /**
     * Register dedicated Astraea management pages in admin menu.
     */
    public static function registerAdminPages(): void {
        // Dedicated top-level "Security" menu in admin sidebar
        add_menu_page(
            'GeDefense Security Center',
            'Security',
            'manage_options',
            'astraea-security',
            [self::class, 'renderDedicatedSecurityPage'],
            'dashicons-shield',
            58
        );

        add_submenu_page(
            'astraea-security',
            'GeDefense Security Overview',
            'Overview',
            'manage_options',
            'astraea-security',
            [self::class, 'renderDedicatedSecurityPage']
        );

        add_submenu_page(
            'astraea-security',
            'Authentication & Sessions',
            'Sessions',
            'manage_options',
            'astraea-security-sessions',
            static function(): void {
                $_GET['tab'] = 'sessions';
                self::renderDedicatedSecurityPage();
            }
        );

        add_submenu_page(
            'astraea-security',
            'Core Integrity Verification',
            'Integrity',
            'manage_options',
            'astraea-security-integrity',
            static function(): void {
                $_GET['tab'] = 'integrity';
                self::renderDedicatedSecurityPage();
            }
        );

        add_submenu_page(
            'astraea-security',
            'Disaster Recovery & Vault',
            'Vault',
            'manage_options',
            'astraea-security-vault',
            static function(): void {
                $_GET['tab'] = 'vault';
                self::renderDedicatedSecurityPage();
            }
        );

        add_submenu_page(
            'astraea-security',
            'Security Event Audit Log',
            'Events',
            'manage_options',
            'astraea-security-events',
            static function(): void {
                $_GET['tab'] = 'events';
                self::renderDedicatedSecurityPage();
            }
        );

        // Keep backwards compatibility for index.php?page=astraea-security
        add_submenu_page(
            'index.php',
            'GeDefense Security HUD',
            'GeDefense HUD',
            'manage_options',
            'astraea-security',
            [self::class, 'renderDedicatedSecurityPage']
        );
    }

    /**
     * Render the main Control Center HUD on the Dashboard (replacing welcome panel).
     */
    public static function renderControlCenter(): void {
        $telemetry = self::collectSystemTelemetry();
        $security  = self::collectSecurityStatus();
        $content   = self::collectContentPulse();
        ?>
        <div class="astraea-control-center">
            <!-- Header Banner -->
            <div class="astraea-cc-banner astraea-glass-surface-l2">
                <div class="astraea-cc-banner-left">
                    <div class="astraea-cc-emblem">
                        <img src="<?php echo esc_url(function_exists('site_url') ? site_url('/astraea-core/AdminUI/assets/img/astraea-brand-2026.png') : content_url('../astraea-core/AdminUI/assets/img/astraea-brand-2026.png')); ?>" alt="AstraeaOS Emblem" width="64" height="64" style="width:64px;height:64px;object-fit:contain;" />
                    </div>
                    <div class="astraea-cc-titlegroup">
                        <div class="astraea-cc-badge">ASTRAEA OPERATING SYSTEM &bull; WP KERNEL</div>
                        <h2 class="astraea-cc-heading">Mission Control Center</h2>
                        <p class="astraea-cc-subtext">Hardened high-performance distribution. Decades of legacy baggage eliminated.</p>
                    </div>
                </div>
                <div class="astraea-cc-banner-right">
                    <?php
                    $overallStatus = $security['status_enum'] ?? HealthStatus::HEALTHY;
                    $badgeClass = match ($overallStatus) {
                        HealthStatus::HEALTHY => 'active',
                        HealthStatus::WARNING => 'warning',
                        HealthStatus::CRITICAL => 'critical',
                        default => 'degraded',
                    };
                    ?>
                    <div class="astraea-status-badge <?php echo esc_attr($badgeClass); ?>">
                        <span class="pulse-dot"></span> SYSTEM <?php echo esc_html($overallStatus->value); ?>
                    </div>
                    <div class="astraea-banner-actions">
                        <button type="button" class="astraea-btn astraea-btn-primary astraea-command-trigger">
                            <span class="btn-icon">&#x2315;</span> Command Palette
                        </button>
                    </div>
                </div>
            </div>

            <!-- HUD Grid -->
            <div class="astraea-cc-grid">
                <!-- 1. GeDefense Security HUD -->
                <div class="astraea-cc-card astraea-glass-surface-l1">
                    <div class="card-header">
                        <div class="card-title">
                            <span class="card-icon shield-icon">&#x1F6E1;</span>
                            <h3>GeDefense Security Kernel</h3>
                        </div>
                        <span class="astraea-status-pill <?php echo esc_attr($security['status_enum']->cssClass()); ?>">
                            <?php echo esc_html($security['status_enum']->value); ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="astraea-telemetry-list">
                            <div class="telemetry-row">
                                <span class="lbl">Cerberus L0 Firewall</span>
                                <span class="val <?php echo esc_attr($security['cerberus']['status']->cssClass()); ?>">
                                    <span class="check">&#x2714;</span> <?php echo esc_html($security['cerberus']['label']); ?> (<?php echo esc_html($security['cerberus']['details']); ?>)
                                </span>
                            </div>
                            <div class="telemetry-row">
                                <span class="lbl">Zeus Cryptography</span>
                                <span class="val <?php echo esc_attr($security['zeus']['status']->cssClass()); ?>">
                                    <span class="check">&#x2714;</span> <?php echo esc_html($security['zeus']['label']); ?> (<?php echo esc_html($security['zeus']['details']); ?>)
                                </span>
                            </div>
                            <div class="telemetry-row">
                                <span class="lbl">GeDefense Aegis DPI</span>
                                <span class="val <?php echo esc_attr($security['aegis']['status']->cssClass()); ?>">
                                    <span class="check">&#x2714;</span> <?php echo esc_html($security['aegis']['label']); ?> (<?php echo esc_html($security['aegis']['details']); ?>)
                                </span>
                            </div>
                            <div class="telemetry-row">
                                <span class="lbl">Titan / HTTP Policy</span>
                                <span class="val <?php echo esc_attr($security['titan']['status']->cssClass()); ?>">
                                    <?php echo esc_html($security['titan']['label']); ?> (<?php echo esc_html($security['titan']['details']); ?>)
                                </span>
                            </div>
                            <div class="telemetry-row">
                                <span class="lbl">Astraea FileGuard</span>
                                <span class="val <?php echo esc_attr($security['fileguard']['status']->cssClass()); ?>">
                                    <span class="check">&#x2714;</span> <?php echo esc_html($security['fileguard']['label']); ?> (<?php echo esc_html($security['fileguard']['details']); ?>)
                                </span>
                            </div>
                            <div class="telemetry-row">
                                <span class="lbl">Astraea Vault Continuity</span>
                                <span class="val <?php echo esc_attr($security['vault']['status']->cssClass()); ?>">
                                    <span class="check">&#x2714;</span> <?php echo esc_html($security['vault']['label']); ?> (<?php echo esc_html($security['vault']['details']); ?>)
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <a href="<?php echo esc_url(admin_url('index.php?page=astraea-security')); ?>" class="astraea-card-link">
                            View Full Security Telemetry &rarr;
                        </a>
                    </div>
                </div>

                <!-- 2. System Telemetry -->
                <div class="astraea-cc-card astraea-glass-surface-l1">
                    <div class="card-header">
                        <div class="card-title">
                            <span class="card-icon cpu-icon">&#x2699;</span>
                            <h3>Engine Telemetry</h3>
                        </div>
                        <span class="astraea-status-pill info">PHP <?php echo esc_html($telemetry['php_version']); ?></span>
                    </div>
                    <div class="card-body">
                        <div class="astraea-telemetry-list">
                            <div class="telemetry-row">
                                <span class="lbl">Distribution Version</span>
                                <span class="val highlight"><?php echo esc_html($telemetry['version']); ?></span>
                            </div>
                            <div class="telemetry-row">
                                <span class="lbl">Database Engine</span>
                                <span class="val"><?php echo esc_html($telemetry['db_server']); ?></span>
                            </div>
                            <div class="telemetry-row">
                                <span class="lbl">Memory Allocation</span>
                                <span class="val"><?php echo esc_html($telemetry['memory_used']); ?> / <?php echo esc_html($telemetry['memory_limit']); ?></span>
                            </div>
                            <div class="telemetry-row">
                                <span class="lbl">OpCache Acceleration</span>
                                <span class="val <?php echo $telemetry['opcache_enabled'] ? 'success' : 'warning'; ?>">
                                    <?php echo $telemetry['opcache_enabled'] ? '&#x2714; Enabled' : 'Disabled'; ?>
                                </span>
                            </div>
                            <div class="telemetry-row">
                                <span class="lbl">Server Architecture</span>
                                <span class="val"><?php echo esc_html($telemetry['os']); ?> (<?php echo esc_html($telemetry['arch']); ?>)</span>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <a href="<?php echo esc_url(admin_url('site-health.php')); ?>" class="astraea-card-link">
                            Run Site Health Diagnostics &rarr;
                        </a>
                    </div>
                </div>

                <!-- 3. Content Pulse -->
                <div class="astraea-cc-card astraea-glass-surface-l1">
                    <div class="card-header">
                        <div class="card-title">
                            <span class="card-icon pulse-icon">&#x25CE;</span>
                            <h3>Content Pulse</h3>
                        </div>
                        <span class="astraea-status-pill default"><?php echo (int) $content['total_items']; ?> Total</span>
                    </div>
                    <div class="card-body">
                        <div class="astraea-metrics-grid">
                            <div class="metric-box">
                                <span class="metric-num"><?php echo (int) $content['posts']; ?></span>
                                <span class="metric-name">Posts</span>
                            </div>
                            <div class="metric-box">
                                <span class="metric-num"><?php echo (int) $content['pages']; ?></span>
                                <span class="metric-name">Pages</span>
                            </div>
                            <div class="metric-box">
                                <span class="metric-num"><?php echo (int) $content['media']; ?></span>
                                <span class="metric-name">Media</span>
                            </div>
                            <div class="metric-box">
                                <span class="metric-num"><?php echo (int) $content['comments']; ?></span>
                                <span class="metric-name">Comments</span>
                            </div>
                        </div>
                        <div class="astraea-quick-shortcuts">
                            <a href="<?php echo esc_url(admin_url('post-new.php')); ?>" class="astraea-btn astraea-btn-sm astraea-btn-ghost">
                                + New Post
                            </a>
                            <a href="<?php echo esc_url(admin_url('post-new.php?post_type=page')); ?>" class="astraea-btn astraea-btn-sm astraea-btn-ghost">
                                + New Page
                            </a>
                            <a href="<?php echo esc_url(admin_url('upload.php')); ?>" class="astraea-btn astraea-btn-sm astraea-btn-ghost">
                                Media Library
                            </a>
                        </div>
                    </div>
                    <div class="card-footer">
                        <a href="<?php echo esc_url(admin_url('edit.php')); ?>" class="astraea-card-link">
                            Manage Published Content &rarr;
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the dedicated GeDefense Security telemetry screen.
     */
    public static function renderDedicatedSecurityPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.'));
        }

        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key($_GET['page']) : '';
        $tab = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
        if ($tab === '') {
            $tab = match ($page) {
                'astraea-security-sessions'  => 'sessions',
                'astraea-security-integrity' => 'integrity',
                'astraea-security-vault'     => 'vault',
                'astraea-security-events'    => 'events',
                default                      => 'overview',
            };
        }
        $validTabs = ['overview', 'integrity', 'sessions', 'vault', 'events'];
        if (!in_array($tab, $validTabs, true)) {
            $tab = 'overview';
        }

        $telemetry     = self::collectSystemTelemetry();
        $security      = self::collectSecurityStatus();
        $posture       = SecurityProbeManager::getOverallPostureSummary();
        $overallStatus = $posture['overall'];
        $overallClass  = match ($overallStatus) {
            HealthStatus::HEALTHY => 'active',
            HealthStatus::WARNING => 'warning',
            HealthStatus::CRITICAL => 'critical',
            default => 'degraded',
        };
        ?>
        <div class="wrap astraea-admin-wrap">
            <div class="astraea-page-header">
                <div class="astraea-page-brand">
                    <img src="<?php echo esc_url(function_exists('site_url') ? site_url('/astraea-core/AdminUI/assets/img/astraea-brand-2026.png') : content_url('../astraea-core/AdminUI/assets/img/astraea-brand-2026.png')); ?>" alt="AstraeaOS Logo" class="astraea-page-logo" />
                    <div class="astraea-page-copy">
                        <span class="astraea-page-eyebrow">SOVEREIGN DEFENSE FABRIC</span>
                        <h1 class="astraea-page-title">GeDefense Multi-Layer Security HUD</h1>
                        <p class="astraea-page-sub">Evidence-based defense architecture embedded in the AstraeaOS WP core.</p>
                    </div>
                </div>
                <div class="astraea-page-health">
                    <span class="astraea-status-badge <?php echo esc_attr($overallClass); ?>">
                        <span class="pulse-dot"></span> SYSTEM <?php echo esc_html($overallStatus->value); ?>
                    </span>
                    <span class="astraea-page-build">ASTRAEA <?php echo esc_html((string)$telemetry['version']); ?></span>
                </div>
            </div>

            <!-- Evidence-Based Security Posture Header Strip (Zero Arbitrary Scoring) -->
            <div class="astraea-posture-ribbon" aria-label="Security Posture Telemetry">
                <div class="astraea-posture-item is-verified">
                    <span class="posture-icon">&#x2714;</span>
                    <div class="posture-meta">
                        <span class="posture-num"><?php echo (int) $posture['verified_count']; ?></span>
                        <span class="posture-label">Verified Checks</span>
                    </div>
                </div>
                <div class="astraea-posture-item is-warning">
                    <span class="posture-icon">&#x26A0;</span>
                    <div class="posture-meta">
                        <span class="posture-num"><?php echo (int) $posture['warning_count']; ?></span>
                        <span class="posture-label">Security Warnings</span>
                    </div>
                </div>
                <div class="astraea-posture-item is-attention">
                    <span class="posture-icon">&#x25CE;</span>
                    <div class="posture-meta">
                        <span class="posture-num"><?php echo (int) $posture['not_configured_count']; ?></span>
                        <span class="posture-label">Not Configured / Idle</span>
                    </div>
                </div>
                <div class="astraea-posture-item is-verified-time">
                    <span class="posture-icon">&#x23F2;</span>
                    <div class="posture-meta">
                        <span class="posture-time"><?php echo esc_html($posture['last_verified']); ?></span>
                        <span class="posture-label">Live Verified Timestamp</span>
                    </div>
                </div>
            </div>

            <!-- Tab Navigation Bar -->
            <nav class="astraea-tabs-nav" aria-label="Security HUD Navigation">
                <a href="<?php echo esc_url(admin_url('admin.php?page=astraea-security&tab=overview')); ?>" class="astraea-tab-link <?php echo $tab === 'overview' ? 'is-active' : ''; ?>">
                    <span class="tab-icon">&#x1F6E1;</span> Overview
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=astraea-security&tab=integrity')); ?>" class="astraea-tab-link <?php echo $tab === 'integrity' ? 'is-active' : ''; ?>">
                    <span class="tab-icon">&#x1F50D;</span> Core Integrity
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=astraea-security&tab=sessions')); ?>" class="astraea-tab-link <?php echo $tab === 'sessions' ? 'is-active' : ''; ?>">
                    <span class="tab-icon">&#x1F511;</span> Authentication &amp; Sessions
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=astraea-security&tab=vault')); ?>" class="astraea-tab-link <?php echo $tab === 'vault' ? 'is-active' : ''; ?>">
                    <span class="tab-icon">&#x1F4BE;</span> Disaster Recovery &amp; Vault
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=astraea-security&tab=events')); ?>" class="astraea-tab-link <?php echo $tab === 'events' ? 'is-active' : ''; ?>">
                    <span class="tab-icon">&#x1F4DC;</span> Security Event Audit
                </a>
            </nav>

            <div class="astraea-tab-content">
                <?php
                match ($tab) {
                    'integrity' => self::renderTabIntegrity(),
                    'sessions'  => self::renderTabSessions(),
                    'vault'     => self::renderTabVault(),
                    'events'    => self::renderTabEvents(),
                    default     => self::renderTabOverview($security, $telemetry),
                };
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render Tab: Overview (GeDefense Multi-Layer Defense Fabric).
     *
     * @param array<string, mixed> $security
     * @param array<string, mixed> $telemetry
     */
    private static function renderTabOverview(array $security, array $telemetry): void {
        $morpheus = SecurityProbeManager::probeMorpheus();
        ?>
        <div class="astraea-signal-strip" aria-label="Security telemetry summary">
            <div class="astraea-signal"><span class="signal-label">Measured probes</span><strong>09</strong><span class="signal-state">evidence-based</span></div>
            <div class="astraea-signal"><span class="signal-label">Crypto probe</span><strong><?php echo esc_html($security['zeus']['label']); ?></strong><span class="signal-state"><?php echo esc_html($security['zeus']['status']->value); ?></span></div>
            <div class="astraea-signal"><span class="signal-label">Runtime</span><strong><?php echo esc_html((string)$telemetry['php_version']); ?></strong><span class="signal-state">PHP</span></div>
            <div class="astraea-signal"><span class="signal-label">Runtime CDN policy</span><strong>NONE</strong><span class="signal-state">zero-dependency</span></div>
        </div>

        <div class="astraea-cc-grid security-details-grid">
            <!-- 1. Cerberus L0 -->
            <div class="astraea-cc-card astraea-glass-surface-l2 is-cerberus">
                <div class="card-header">
                    <div class="card-title">
                        <span class="card-icon">&#x1F6E1;</span>
                        <h3>Cerberus L0 Request Firewall</h3>
                    </div>
                    <span class="astraea-status-pill <?php echo esc_attr($security['cerberus']['status']->cssClass()); ?>">
                        <?php echo esc_html($security['cerberus']['label']); ?>
                    </span>
                </div>
                <div class="card-body">
                    <p class="card-desc"><?php echo esc_html($security['cerberus']['details']); ?></p>
                    <ul class="astraea-spec-list">
                        <li><strong>Runtime evidence:</strong> <?php echo esc_html($security['cerberus']['status']->value); ?></li>
                        <li><strong>Probe:</strong> live class/runtime engagement check</li>
                    </ul>
                </div>
            </div>

            <!-- 2. Zeus Cryptography -->
            <div class="astraea-cc-card astraea-glass-surface-l2 is-zeus">
                <div class="card-header">
                    <div class="card-title">
                        <span class="card-icon">&#x26A1;</span>
                        <h3>Zeus Cryptographic Kernel</h3>
                    </div>
                    <span class="astraea-status-pill <?php echo esc_attr($security['zeus']['status']->cssClass()); ?>">
                        <?php echo esc_html($security['zeus']['label']); ?>
                    </span>
                </div>
                <div class="card-body">
                    <p class="card-desc"><?php echo esc_html($security['zeus']['details']); ?></p>
                    <ul class="astraea-spec-list">
                        <li><strong>Password Hashing Algorithm:</strong> Argon2id</li>
                        <li><strong>Symmetric Encryption:</strong> libsodium XChaCha20-Poly1305 / AES-256-GCM</li>
                        <li><strong>Key Derivation:</strong> HKDF context separation</li>
                        <li><strong>Keyring rotation support:</strong> versioned ACTIVE / DECRYPT_ONLY key states</li>
                    </ul>
                </div>
            </div>

            <!-- 3. GeDefense Aegis DPI -->
            <div class="astraea-cc-card astraea-glass-surface-l2 is-aegis">
                <div class="card-header">
                    <div class="card-title">
                        <span class="card-icon">&#x1F512;</span>
                        <h3>GeDefense Aegis DPI</h3>
                    </div>
                    <span class="astraea-status-pill <?php echo esc_attr($security['aegis']['status']->cssClass()); ?>">
                        <?php echo esc_html($security['aegis']['label']); ?>
                    </span>
                </div>
                <div class="card-body">
                    <p class="card-desc"><?php echo esc_html($security['aegis']['details']); ?></p>
                    <ul class="astraea-spec-list">
                        <li><strong>Role:</strong> request payload and attack-vector inspection</li>
                        <li><strong>Runtime evidence:</strong> <?php echo esc_html($security['aegis']['status']->value); ?></li>
                    </ul>
                </div>
            </div>

            <!-- 4. Titan / HTTP Policy -->
            <div class="astraea-cc-card astraea-glass-surface-l2 is-aegis">
                <div class="card-header">
                    <div class="card-title"><span class="card-icon">&#x1F310;</span><h3>GeDefense Titan / HTTP Policy</h3></div>
                    <span class="astraea-status-pill <?php echo esc_attr($security['titan']['status']->cssClass()); ?>"><?php echo esc_html($security['titan']['label']); ?></span>
                </div>
                <div class="card-body">
                    <p class="card-desc"><?php echo esc_html($security['titan']['details']); ?></p>
                    <ul class="astraea-spec-list">
                        <li><strong>Titan:</strong> authoritative HTTP/browser policy when enabled</li>
                        <li><strong>Astraea HeaderPolicy:</strong> baseline/fallback only</li>
                        <li><strong>HSTS preload:</strong> never assumed; explicit policy only</li>
                    </ul>
                </div>
            </div>

            <!-- 5. Astraea FileGuard -->
            <div class="astraea-cc-card astraea-glass-surface-l2 is-hades">
                <div class="card-header">
                    <div class="card-title">
                        <span class="card-icon">&#x1F527;</span>
                        <h3>Astraea FileGuard</h3>
                    </div>
                    <span class="astraea-status-pill <?php echo esc_attr($security['fileguard']['status']->cssClass()); ?>">
                        <?php echo esc_html($security['fileguard']['label']); ?>
                    </span>
                </div>
                <div class="card-body">
                    <p class="card-desc"><?php echo esc_html($security['fileguard']['details']); ?></p>
                    <ul class="astraea-spec-list">
                        <li><strong>Upload prefilter:</strong> <?php echo !empty($security['fileguard']['upload_filter']) ? 'VERIFIED' : 'NOT VERIFIED'; ?></li>
                        <li><strong>MIME/extension filter:</strong> <?php echo !empty($security['fileguard']['mime_filter']) ? 'VERIFIED' : 'NOT VERIFIED'; ?></li>
                        <li><strong>Apache uploads execution barrier:</strong> <?php echo !empty($security['fileguard']['apache_barrier']) ? 'VERIFIED' : 'NOT ASSERTED'; ?></li>
                    </ul>
                </div>
            </div>

            <!-- 5. Morpheus Sandbox -->
            <div class="astraea-cc-card astraea-glass-surface-l2 is-morpheus">
                <div class="card-header">
                    <div class="card-title">
                        <span class="card-icon">&#x1F9E0;</span>
                        <h3>Morpheus RASP Sandbox</h3>
                    </div>
                    <span class="astraea-status-pill <?php echo esc_attr($morpheus['status']->cssClass()); ?>">
                        <?php echo esc_html($morpheus['label']); ?>
                    </span>
                </div>
                <div class="card-body">
                    <p class="card-desc"><?php echo esc_html($morpheus['details']); ?></p>
                    <ul class="astraea-spec-list">
                        <li><strong>Runtime evidence:</strong> <?php echo esc_html($morpheus['status']->value); ?></li>
                        <li><strong>Policy state:</strong> <?php echo esc_html($morpheus['label']); ?></li>
                    </ul>
                </div>
            </div>

            <!-- 6. Astraea Vault -->
            <div class="astraea-cc-card astraea-glass-surface-l2 is-vault">
                <div class="card-header">
                    <div class="card-title">
                        <span class="card-icon">&#x1F4BE;</span>
                        <h3>Astraea Vault Continuity</h3>
                    </div>
                    <span class="astraea-status-pill <?php echo esc_attr($security['vault']['status']->cssClass()); ?>">
                        <?php echo esc_html($security['vault']['label']); ?>
                    </span>
                </div>
                <div class="card-body">
                    <p class="card-desc"><?php echo esc_html($security['vault']['details']); ?></p>
                    <ul class="astraea-spec-list">
                        <li><strong>Recovery Gate:</strong> <?php echo !empty($security['vault']['recovery_gate_ready']) ? 'HOOK VERIFIED' : 'NOT VERIFIED'; ?></li>
                        <li><strong>Update Guard:</strong> <?php echo !empty($security['vault']['update_guard_ready']) ? 'HOOK VERIFIED' : 'NOT VERIFIED'; ?></li>
                        <li><strong>Latest backup:</strong> <?php echo esc_html((string)($security['vault']['latest_backup_id'] ?? 'none')); ?><?php echo !empty($security['vault']['latest_verified']) ? ' (VERIFIED)' : ''; ?></li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Tab: Core Cryptographic Integrity Center.
     */
    private static function renderTabIntegrity(): void {
        $deep = SecurityProbeManager::probeIntegrityDeep();
        $manifestPath = dirname(__DIR__, 2) . '/BUILD-MANIFEST.json';
        $manifestData = null;
        if (file_exists($manifestPath)) {
            $manifestData = json_decode((string) file_get_contents($manifestPath), true);
        }
        $files = is_array($manifestData) && isset($manifestData['files']) && is_array($manifestData['files'])
            ? $manifestData['files']
            : [];
        $rootHash = is_array($manifestData) && isset($manifestData['root_hash']) ? (string)$manifestData['root_hash'] : 'N/A';
        $buildDate = is_array($manifestData) && isset($manifestData['build_date']) ? (string)$manifestData['build_date'] : 'N/A';

        $hasViolations = !empty($deep['modified_files']) || !empty($deep['missing_files']) || !empty($deep['unexpected_files']);
        $authenticity = isset($deep['authenticity']) && is_array($deep['authenticity']) ? $deep['authenticity'] : ['status' => 'UNKNOWN', 'details' => 'Release authenticity evidence unavailable.'];
        ?>
        <div class="astraea-signal-strip" aria-label="Integrity Telemetry">
            <div class="astraea-signal"><span class="signal-label">Manifest</span><strong><?php echo $deep['manifest_present'] ? 'LOADED' : 'MISSING'; ?></strong><span class="signal-state"><?php echo $deep['manifest_present'] ? 'active' : 'alert'; ?></span></div>
            <div class="astraea-signal"><span class="signal-label">Release authenticity</span><strong><?php echo esc_html((string)($authenticity['status'] ?? 'UNKNOWN')); ?></strong><span class="signal-state"><?php echo !empty($authenticity['verified']) ? 'Ed25519 verified' : 'local integrity only'; ?></span></div>
            <div class="astraea-signal"><span class="signal-label">Monitored</span><strong><?php echo (int) $deep['total_manifest_files']; ?></strong><span class="signal-state">files</span></div>
            <div class="astraea-signal"><span class="signal-label">Intact</span><strong><?php echo (int) $deep['intact_count']; ?></strong><span class="signal-state">verified</span></div>
            <div class="astraea-signal"><span class="signal-label">Modified</span><strong><?php echo count($deep['modified_files']); ?></strong><span class="signal-state <?php echo count($deep['modified_files']) > 0 ? 'alert' : 'clean'; ?>">mismatch</span></div>
            <div class="astraea-signal"><span class="signal-label">Missing</span><strong><?php echo count($deep['missing_files']); ?></strong><span class="signal-state <?php echo count($deep['missing_files']) > 0 ? 'alert' : 'clean'; ?>">absent</span></div>
            <div class="astraea-signal"><span class="signal-label">Unexpected</span><strong><?php echo count($deep['unexpected_files'] ?? []); ?></strong><span class="signal-state <?php echo count($deep['unexpected_files'] ?? []) > 0 ? 'alert' : 'clean'; ?>">unmanifested</span></div>
        </div>

        <?php if ($hasViolations): ?>
            <div class="astraea-alert-banner astraea-alert-critical">
                <span class="alert-icon">&#x26A0;</span>
                <div class="alert-copy">
                    <strong>CRITICAL INTEGRITY ANOMALY DETECTED</strong>
                    <p>Core files do not match the local SHA-256 build manifest. Potential unauthorized modification or incomplete update.</p>
                    <?php if (!empty($deep['modified_files'])): ?>
                        <div class="alert-list-group">
                            <span>Modified:</span>
                            <code><?php 
                                $modNames = array_map(static fn($m): string => is_array($m) ? (string) ($m['file'] ?? 'unknown') : (string) $m, $deep['modified_files']);
                                echo esc_html(implode(', ', $modNames)); 
                            ?></code>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($deep['missing_files'])): ?>
                        <div class="alert-list-group">
                            <span>Missing:</span>
                            <code><?php echo esc_html(implode(', ', $deep['missing_files'])); ?></code>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($authenticity['verified'])): ?>
            <div class="astraea-alert-banner astraea-alert-warning">
                <span class="alert-icon">&#x2139;</span>
                <div class="alert-copy"><strong>RELEASE AUTHENTICITY NOT VERIFIED</strong><p><?php echo esc_html((string)($authenticity['details'] ?? 'This build provides local checksums only.')); ?></p></div>
            </div>
        <?php endif; ?>

        <div class="astraea-section-box astraea-glass-surface-l2">
            <div class="section-box-header">
                <div class="section-title">
                    <span class="box-icon">&#x1F50D;</span>
                    <h3>Local SHA-256 File Integrity Manifest</h3>
                </div>
                <div class="section-meta">
                    <span class="astraea-meta-pill">Root Hash: <code><?php echo esc_html(substr($rootHash, 0, 16)); ?>...</code></span>
                    <span class="astraea-meta-pill">Built: <?php echo esc_html($buildDate); ?></span>
                </div>
            </div>

            <div class="astraea-table-container">
                <table class="astraea-data-table">
                    <thead>
                        <tr>
                            <th>File Path (Relative)</th>
                            <th>Expected SHA-256 Checksum</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($files)): ?>
                            <tr>
                                <td colspan="3" class="empty-state">No manifest entries registered or manifest missing.</td>
                            </tr>
                        <?php else:
                            $baseDir = dirname(__DIR__, 2);
                            foreach ($files as $relPath => $expectedHash):
                                $fullPath = $baseDir . '/' . $relPath;
                                $status = 'INTACT';
                                $statusClass = 'healthy';
                                if (!file_exists($fullPath)) {
                                    $status = 'MISSING';
                                    $statusClass = 'critical';
                                } else {
                                    $actualHash = hash_file('sha256', $fullPath);
                                    if ($actualHash !== $expectedHash) {
                                        $status = 'MODIFIED';
                                        $statusClass = 'critical';
                                    }
                                }
                                ?>
                                <tr>
                                    <td class="file-path"><code><?php echo esc_html($relPath); ?></code></td>
                                    <td class="hash-col" title="<?php echo esc_attr((string)$expectedHash); ?>">
                                        <code><?php echo esc_html(substr((string)$expectedHash, 0, 16)); ?>...</code>
                                    </td>
                                    <td>
                                        <span class="astraea-badge <?php echo esc_attr($statusClass); ?>">
                                            <?php echo esc_html($status); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Render Tab: Authentication & Session Management.
     */
    private static function renderTabSessions(): void {
        $auth = SecurityProbeManager::probeAuthentication();
        $currentUserId = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        $sessions = SessionManager::getUserSessions($currentUserId);
        $isStepUp = StepUpAuthService::isStepUpActive($currentUserId);
        ?>
        <div class="astraea-signal-strip" aria-label="Authentication telemetry">
            <div class="astraea-signal"><span class="signal-label">Password algorithm</span><strong>Argon2id</strong><span class="signal-state">hardened</span></div>
            <div class="astraea-signal"><span class="signal-label">Cryptographic pepper</span><strong><?php echo $auth['pepper_configured'] ? 'ACTIVE' : 'IDLE'; ?></strong><span class="signal-state"><?php echo $auth['pepper_configured'] ? 'external secret' : 'optional'; ?></span></div>
            <div class="astraea-signal"><span class="signal-label">MFA / Passkeys</span><strong><?php echo esc_html((string)$auth['passkeys_status']); ?></strong><span class="signal-state">no WebAuthn claim</span></div>
            <div class="astraea-signal"><span class="signal-label">Active sessions</span><strong><?php echo (int)$auth['active_sessions']; ?></strong><span class="signal-state">monitored</span></div>
        </div>

        <div class="astraea-stepup-card astraea-glass-surface-l1">
            <div class="stepup-meta">
                <span class="stepup-icon">&#x1F510;</span>
                <div class="stepup-text">
                    <h4>Step-Up Privileged Authentication</h4>
                    <p>Enforces challenge-response elevation before executing destructive tasks (credential rotation, core modifications, recovery operations). Grants a fixed 15-minute elevation bound to this exact WordPress session. Other sessions do not inherit it.</p>
                </div>
            </div>
            <div class="stepup-status">
                <span class="astraea-badge <?php echo $isStepUp ? 'healthy' : 'default'; ?>"><?php echo $isStepUp ? '&#x2714; THIS SESSION ELEVATED' : 'CHALLENGE REQUIRED'; ?></span>
                <?php if (!$isStepUp): ?>
                    <?php
                    $fallbackUrl = admin_url('admin.php?page=astraea-security&tab=sessions');
                    $redirectUrl = !empty($_GET['redirect_to']) && is_string($_GET['redirect_to'])
                        ? wp_validate_redirect((string)wp_unslash($_GET['redirect_to']), $fallbackUrl)
                        : $fallbackUrl;
                    if ($redirectUrl !== $fallbackUrl): ?>
                        <div style="margin-bottom:12px;padding:10px 14px;background:rgba(24,215,255,0.12);border-left:3px solid #18d7ff;border-radius:8px;font-size:13px;color:#dff8ff;">
                            <strong>Re-Authentifizierung erforderlich:</strong> Best&auml;tige deine Sitzung mit deinem Passwort, um zu deiner vorherigen Aktion zur&uuml;ckzukehren.
                        </div>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="astraea-stepup-form" autocomplete="off">
                        <input type="hidden" name="action" value="<?php echo esc_attr(StepUpAuthService::ADMIN_POST_ACTION); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url($redirectUrl); ?>">
                        <?php wp_nonce_field(StepUpAuthService::NONCE_ACTION); ?>
                        <label for="astraea-stepup-password" class="screen-reader-text">Current password</label>
                        <input id="astraea-stepup-password" type="password" name="astraea_password" autocomplete="current-password" required placeholder="Current password">
                        <button type="submit" class="astraea-btn astraea-btn-primary astraea-btn-sm">Re-authenticate</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="astraea-section-box astraea-glass-surface-l2">
            <div class="section-box-header">
                <div class="section-title">
                    <span class="box-icon">&#x1F4BB;</span>
                    <h3>Active Authorized Sessions (User ID #<?php echo (int)$currentUserId; ?>)</h3>
                </div>
                <div class="section-actions">
                    <button type="button" id="astraea-revoke-all-others-btn" class="astraea-btn astraea-btn-danger astraea-btn-sm" data-action="revoke-all-other-sessions">
                        Log Out All Other Sessions
                    </button>
                </div>
            </div>

            <div class="astraea-table-container">
                <table class="astraea-data-table">
                    <thead>
                        <tr>
                            <th>Device &amp; Operating System</th>
                            <th>Browser &amp; Client</th>
                            <th>Network Address (Masked)</th>
                            <th>Login Timestamp</th>
                            <th>Session Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="astraea-sessions-list">
                        <?php if (empty($sessions)): ?>
                            <tr>
                                <td colspan="6" class="empty-state">No active session records found.</td>
                            </tr>
                        <?php else:
                            foreach ($sessions as $sess):
                                $dev = $sess['device'] ?? [];
                                $os = $dev['os'] ?? 'Unknown OS';
                                $browser = $dev['browser'] ?? 'Unknown Browser';
                                $ipMasked = $sess['ip_masked'] ?? '0.0.0.0';
                                $loginTime = !empty($sess['login_time']) ? gmdate('Y-m-d H:i:s \U\T\C', (int)$sess['login_time']) : 'Unknown';
                                $isCurrent = !empty($sess['is_current']);
                                $verifier = (string)($sess['verifier'] ?? '');
                                ?>
                                <tr id="session-row-<?php echo esc_attr(substr($verifier, 0, 16)); ?>">
                                    <td>
                                        <div class="device-cell">
                                            <span class="device-os-pill"><?php echo esc_html($os); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="browser-name" title="<?php echo esc_attr((string)($sess['raw_ua'] ?? '')); ?>"><?php echo esc_html($browser); ?></span>
                                    </td>
                                    <td>
                                        <code><?php echo esc_html($ipMasked); ?></code>
                                    </td>
                                    <td>
                                        <span class="timestamp-text"><?php echo esc_html($loginTime); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($isCurrent): ?>
                                            <span class="astraea-badge healthy">&#x25CF; This Device</span>
                                        <?php else: ?>
                                            <span class="astraea-badge default">Active Remote</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$isCurrent && $verifier !== ''): ?>
                                            <button type="button" class="astraea-btn astraea-btn-xs astraea-btn-danger astraea-session-revoke-btn" data-verifier="<?php echo esc_attr($verifier); ?>">
                                                Revoke
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Render Tab: Astraea Vault Disaster Recovery Dashboard.
     */
    private static function renderTabVault(): void {
        $vault = SecurityProbeManager::probeVaultDeep();
        $containers = $vault['containers'] ?? [];
        ?>
        <div class="astraea-signal-strip astraea-signal-strip-5" aria-label="Vault Disaster Recovery Telemetry">
            <div class="astraea-signal"><span class="signal-label">Recovery gate</span><strong><?php echo esc_html((string)$vault['recovery_gate']); ?></strong><span class="signal-state">pre-plugin</span></div>
            <div class="astraea-signal"><span class="signal-label">Storage permissions</span><strong><?php echo $vault['storage_writable'] ? 'READ/WRITE' : 'READ-ONLY'; ?></strong><span class="signal-state"><?php echo $vault['storage_writable'] ? 'healthy' : 'alert'; ?></span></div>
            <div class="astraea-signal"><span class="signal-label">Backup verification</span><strong><?php echo esc_html((string)$vault['verification_status']); ?></strong><span class="signal-state"><?php echo esc_html((string)$vault['last_verification_time']); ?></span></div>
            <div class="astraea-signal"><span class="signal-label">Encrypted containers</span><strong><?php echo (int)$vault['container_count']; ?></strong><span class="signal-state">AVB</span></div>
            <div class="astraea-signal"><span class="signal-label">Update Guard</span><strong><?php echo esc_html((string)$vault['update_guard']); ?></strong><span class="signal-state">plugin transactions</span></div>
        </div>

        <div class="astraea-alert-banner astraea-alert-warning">
            <span class="alert-icon">&#x2139;</span>
            <div class="alert-copy">
                <strong>DISASTER RESILIENCE RATING: DEGRADED (SINGLE LOCAL NODE)</strong>
                <p><?php echo esc_html($vault['resilience_notes']); ?></p>
            </div>
        </div>

        <div class="astraea-section-box astraea-glass-surface-l2">
            <div class="section-box-header">
                <div class="section-title">
                    <span class="box-icon">&#x1F4BE;</span>
                    <h3>Astraea Vault Binary (AVB) Containers</h3>
                </div>
                <div class="section-meta">
                    <span class="astraea-meta-pill">Storage Root: <code><?php echo esc_html($vault['storage_path']); ?></code></span>
                </div>
            </div>

            <div class="astraea-table-container">
                <table class="astraea-data-table">
                    <thead>
                        <tr>
                            <th>Container Archive (.avb)</th>
                            <th>Archive Size</th>
                            <th>Created Timestamp</th>
                            <th>Container Spec</th>
                            <th>Authenticity Verification</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($containers)): ?>
                            <tr>
                                <td colspan="5" class="empty-state">
                                    <div class="empty-notice">
                                        <span class="empty-icon">&#x1F4BE;</span>
                                        <strong>No AVB snapshots registered yet</strong>
                                        <p>Astraea Vault can capture verified snapshots before protected plugin updates and can also be triggered manually or via CLI.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else:
                            foreach ($containers as $c):
                                ?>
                                <tr>
                                    <td><code><?php echo esc_html($c['filename']); ?></code></td>
                                    <td><?php echo esc_html($c['size_formatted']); ?></td>
                                    <td><?php echo esc_html($c['created_formatted']); ?></td>
                                    <td><span class="astraea-badge default">AVB AEAD Encrypted</span></td>
                                    <td><span class="astraea-badge <?php echo !empty($c['verified']) ? 'healthy' : 'warning'; ?>"><?php echo !empty($c['verified']) ? '&#x2714; VERIFIED' : esc_html((string)($c['status'] ?? 'UNKNOWN')); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Render Tab: Security Event Audit Trail.
     */
    private static function renderTabEvents(): void {
        $events = SecurityEventManager::getRecentEvents(100);
        ?>
        <div class="astraea-signal-strip" aria-label="Security event telemetry">
            <div class="astraea-signal"><span class="signal-label">Audit mechanism</span><strong>RING-BUFFER</strong><span class="signal-state">200 max FIFO</span></div>
            <div class="astraea-signal"><span class="signal-label">Sanitization</span><strong>ACTIVE</strong><span class="signal-state">secret-key redaction</span></div>
            <div class="astraea-signal"><span class="signal-label">Buffered events</span><strong><?php echo count($events); ?></strong><span class="signal-state">live</span></div>
            <div class="astraea-signal"><span class="signal-label">Subsystem bridges</span><strong><?php echo \Astraea\Security\SecurityEventBridge::isInitialized() ? 'ACTIVE' : 'NOT READY'; ?></strong><span class="signal-state">Vault / GeDefense + direct Auth / Integrity events</span></div>
        </div>

        <div class="astraea-section-box astraea-glass-surface-l2">
            <div class="section-box-header">
                <div class="section-title">
                    <span class="box-icon">&#x1F4DC;</span>
                    <h3>Centralized Security Audit Trail</h3>
                </div>
                <div class="section-meta">
                    <span class="astraea-meta-pill">Context Sanitization: Active</span>
                </div>
            </div>

            <div class="astraea-table-container">
                <table class="astraea-data-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Severity</th>
                            <th>Subsystem</th>
                            <th>Action / Event</th>
                            <th>Origin IP</th>
                            <th>Sanitized Audit Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($events)): ?>
                            <tr>
                                <td colspan="6" class="empty-state">No security events logged in ring buffer.</td>
                            </tr>
                        <?php else:
                            foreach ($events as $ev):
                                $sev = strtoupper((string)($ev['severity'] ?? 'INFO'));
                                $sevClass = match ($sev) {
                                    'CRITICAL' => 'critical',
                                    'WARNING'  => 'warning',
                                    default    => 'default',
                                };
                                $timeStr = !empty($ev['timestamp']) ? gmdate('Y-m-d H:i:s \U\T\C', (int)$ev['timestamp']) : ($ev['datetime'] ?? 'N/A');
                                ?>
                                <tr>
                                    <td class="nowrap"><code><?php echo esc_html($timeStr); ?></code></td>
                                    <td><span class="astraea-badge <?php echo esc_attr($sevClass); ?>"><?php echo esc_html($sev); ?></span></td>
                                    <td><span class="astraea-meta-pill"><?php echo esc_html($ev['subsystem'] ?? 'Core'); ?></span></td>
                                    <td><strong><?php echo esc_html($ev['action'] ?? 'event'); ?></strong></td>
                                    <td><code><?php echo esc_html($ev['ip'] ?? '127.0.0.1'); ?></code></td>
                                    <td class="event-details"><?php echo esc_html($ev['details'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Gather system runtime telemetry.
     *
     * @return array<string, mixed>
     */
    public static function collectSystemTelemetry(): array {
        global $wpdb;

        $opcacheEnabled = function_exists('opcache_get_status') && is_array(@opcache_get_status(false));

        return [
            'version'         => defined('ASTRAEA_VERSION') ? ASTRAEA_VERSION : 'unknown',
            'php_version'     => PHP_VERSION,
            'db_server'       => $wpdb->db_version() ? 'MySQL ' . $wpdb->db_version() : 'Active',
            'memory_used'     => size_format(memory_get_usage(true)),
            'memory_limit'    => ini_get('memory_limit') ?: '256M',
            'opcache_enabled' => $opcacheEnabled,
            'os'              => PHP_OS,
            'arch'            => php_uname('m'),
        ];
    }

    /**
     * Gather real GeDefense and Astraea security probe status.
     *
     * @return array<string, mixed>
     */
    public static function collectSecurityStatus(): array {
        $overall = SecurityProbeManager::getOverallStatus();
        return [
            'status'         => $overall->value,
            'status_enum'    => $overall,
            'cerberus'       => SecurityProbeManager::probeCerberus(),
            'zeus'           => SecurityProbeManager::probeZeusCrypto(),
            'aegis'          => SecurityProbeManager::probeAegis(),
            'titan'          => SecurityProbeManager::probeHttpSecurity(),
            'fileguard'      => SecurityProbeManager::probeFileGuard(),
            'vault'          => SecurityProbeManager::probeVault(),
            'database'       => SecurityProbeManager::probeDatabase(),
            'argon2id'       => defined('PASSWORD_ARGON2ID'),
            'sodium'         => extension_loaded('sodium'),
        ];
    }

    /**
     * Gather content count metrics.
     *
     * @return array<string, int>
     */
    public static function collectContentPulse(): array {
        $posts    = wp_count_posts('post');
        $pages    = wp_count_posts('page');
        $comments = wp_count_comments();
        $media    = wp_count_attachments();

        $pubPosts = (int) ($posts->publish ?? 0);
        $pubPages = (int) ($pages->publish ?? 0);
        $pubComments = (int) ($comments->approved ?? 0);
        $totalMedia  = 0;
        if (is_object($media)) {
            foreach (get_object_vars($media) as $cnt) {
                $totalMedia += (int) $cnt;
            }
        }

        return [
            'posts'       => $pubPosts,
            'pages'       => $pubPages,
            'comments'    => $pubComments,
            'media'       => $totalMedia,
            'total_items' => $pubPosts + $pubPages + $pubComments + $totalMedia,
        ];
    }
}
