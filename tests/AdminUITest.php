<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Tests;

use Astraea\AdminUI\AdminUIBootstrap;
use Astraea\AdminUI\Navigation\NavigationAdapter;
use Astraea\AdminUI\Shell\ShellRenderer;
use Astraea\AdminUI\CommandPalette\CommandRegistry;
use Astraea\AdminUI\Dashboard\ControlCenter;
use Astraea\AdminUI\Login\LoginTheme;

require_once __DIR__ . '/TestCase.php';

/**
 * Test Suite for AstraeaOS Phase 2 Glass Admin UI & Architecture.
 */
final class AdminUITest extends TestCase {

    public static function run(): void {
        echo "Running AdminUITest...\n";

        self::testNavigationCategorization();
        self::testBreadcrumbs();
        self::testShellBrandingFilters();
        self::testCommandPalette();
        self::testControlCenterTelemetry();
        self::testLoginTheme();
        self::testAssetIntegrity();
        self::testComponentPreview();
        self::testAccessibilityAndMotionTokens();

        echo "  -> AdminUITest completed.\n";
    }

    private static function testNavigationCategorization(): void {
        // Content domain
        self::assertEquals(NavigationAdapter::CAT_CONTENT, NavigationAdapter::determineCategory('index.php'));
        self::assertEquals(NavigationAdapter::CAT_CONTENT, NavigationAdapter::determineCategory('edit.php'));
        self::assertEquals(NavigationAdapter::CAT_CONTENT, NavigationAdapter::determineCategory('upload.php'));
        self::assertEquals(NavigationAdapter::CAT_CONTENT, NavigationAdapter::determineCategory('edit.php?post_type=page'));
        self::assertEquals(NavigationAdapter::CAT_CONTENT, NavigationAdapter::determineCategory('edit.php?post_type=product'));

        // Design domain
        self::assertEquals(NavigationAdapter::CAT_DESIGN, NavigationAdapter::determineCategory('themes.php'));
        self::assertEquals(NavigationAdapter::CAT_DESIGN, NavigationAdapter::determineCategory('site-editor.php'));

        // System domain
        self::assertEquals(NavigationAdapter::CAT_SYSTEM, NavigationAdapter::determineCategory('plugins.php'));
        self::assertEquals(NavigationAdapter::CAT_SYSTEM, NavigationAdapter::determineCategory('users.php'));
        self::assertEquals(NavigationAdapter::CAT_SYSTEM, NavigationAdapter::determineCategory('options-general.php'));

        // Astraea domain
        self::assertEquals(NavigationAdapter::CAT_ASTRAEA, NavigationAdapter::determineCategory('astraea-security'));
        self::assertEquals(NavigationAdapter::CAT_ASTRAEA, NavigationAdapter::determineCategory('astraea-health'));

        // Third-party extensions
        self::assertEquals(NavigationAdapter::CAT_EXTENSIONS, NavigationAdapter::determineCategory('woocommerce-settings'));
        self::assertEquals(NavigationAdapter::CAT_EXTENSIONS, NavigationAdapter::determineCategory('elementor'));
    }

    private static function testBreadcrumbs(): void {
        $crumbs = NavigationAdapter::getBreadcrumbs();
        self::assertTrue(!empty($crumbs), 'Breadcrumbs array must not be empty');
        self::assertEquals('AstraeaOS', $crumbs[0]['title']);
    }

    private static function testShellBrandingFilters(): void {
        // Title rewriting
        $title = ShellRenderer::filterAdminTitle('Dashboard &lsaquo; My Site &#8212; WordPress', 'Dashboard');
        self::assertStringContains('— AstraeaOS', $title);
        self::assertFalse(str_contains($title, 'WordPress'));

        // Footer credits rewriting
        $footer = ShellRenderer::filterAdminFooter('Thank you for creating with WordPress.');
        self::assertStringContains('AstraeaOS WP', $footer);
        self::assertStringContains('VisionGaiaTechnology', $footer);
        self::assertFalse(str_contains($footer, 'Thank you for creating with WordPress'));

        // Update footer version check replacement
        $updateFooter = ShellRenderer::filterUpdateFooter('Version 6.8');
        self::assertStringContains('PHP ' . PHP_VERSION, $updateFooter);
        self::assertFalse(str_contains($updateFooter, 'Version 6.8'));

        // Body classes
        $bodyClass = ShellRenderer::filterBodyClasses('wp-admin');
        self::assertStringContains('astraea-glass-shell', $bodyClass);
        self::assertStringContains('astraea-theme-dark', $bodyClass);
    }

    private static function testCommandPalette(): void {
        $actions = CommandRegistry::getStaticActions();
        self::assertTrue(!empty($actions), 'Static actions must not be empty');

        $firstAction = $actions[0];
        self::assertTrue(isset($firstAction['id']));
        self::assertTrue(isset($firstAction['title']));
        self::assertTrue(isset($firstAction['category']));
        self::assertTrue(isset($firstAction['url']));

        // Search test
        $results = CommandRegistry::searchCommands('control center');
        self::assertTrue(!empty($results), 'Search for control center must return results');
        self::assertEquals('Go to Control Center Dashboard', $results[0]['title']);
    }

    private static function testControlCenterTelemetry(): void {
        $telemetry = ControlCenter::collectSystemTelemetry();
        self::assertTrue(isset($telemetry['version']));
        self::assertTrue(isset($telemetry['php_version']));
        self::assertTrue(isset($telemetry['memory_used']));
        self::assertTrue(isset($telemetry['os']));

        $security = ControlCenter::collectSecurityStatus();
        $validStatuses = array_map(fn($c) => $c->value, \Astraea\Diagnostics\HealthStatus::cases());
        self::assertTrue(in_array($security['status'], $validStatuses, true), 'Security status must be valid HealthStatus');
        self::assertTrue($security['status_enum'] instanceof \Astraea\Diagnostics\HealthStatus);
        self::assertTrue($security['argon2id']);
        self::assertTrue(isset($security['cerberus']));
        self::assertTrue(isset($security['zeus']));
        self::assertTrue(isset($security['aegis']));
        self::assertTrue(isset($security['fileguard']));
        self::assertTrue(isset($security['vault']));

        // Verify HTML rendering executes with zero errors
        ob_start();
        ControlCenter::renderControlCenter();
        $ccHtml = ob_get_clean();
        self::assertTrue(!empty($ccHtml), 'Control Center HTML must not be empty');
        self::assertStringContains('astraea-control-center', $ccHtml);
        self::assertStringContains('GeDefense Security Kernel', $ccHtml);

        ob_start();
        ControlCenter::renderDedicatedSecurityPage();
        $secHtml = ob_get_clean();
        self::assertTrue(!empty($secHtml), 'Security Page HTML must not be empty');
        self::assertStringContains('Cerberus L0 Request Firewall', $secHtml);
        self::assertStringContains('Astraea Vault Continuity', $secHtml);
        self::assertStringContains('astraea-signal-strip', $secHtml);
        self::assertStringContains('SOVEREIGN DEFENSE FABRIC', $secHtml);
        self::assertFalse(str_contains($ccHtml, 'onclick='), 'Control Center must not use inline event handlers');
    }

    private static function testLoginTheme(): void {
        $title = LoginTheme::filterHeaderText('Powered by WordPress');
        self::assertEquals('AstraeaOS WP — Core Authentication', $title);

        $bodyClasses = LoginTheme::filterBodyClass(['login']);
        self::assertTrue(in_array('astraea-login-portal', $bodyClasses, true));
        self::assertTrue(in_array('astraea-theme-dark', $bodyClasses, true));
    }

    private static function testAssetIntegrity(): void {
        $baseDir = dirname(__DIR__) . '/astraea-core/AdminUI/assets/';

        // Logo files
        self::assertTrue(file_exists($baseDir . 'img/astraea-logo.png'), 'Logo must exist in assets/img/');
        self::assertTrue(filesize($baseDir . 'img/astraea-logo.png') > 0, 'Logo must not be empty');

        // CSS files
        $cssFiles = [
            'astraea-tokens.css',
            'astraea-glass.css',
            'astraea-shell.css',
            'astraea-components.css',
            'astraea-tables.css',
            'astraea-dashboard.css',
            'astraea-command-palette.css',
            'astraea-notifications.css',
            'astraea-login.css',
            'astraea-gutenberg.css',
        ];

        foreach ($cssFiles as $file) {
            $path = $baseDir . 'css/' . $file;
            self::assertTrue(file_exists($path), "Missing CSS asset: {$file}");
            self::assertTrue(filesize($path) > 100, "Empty or truncated CSS asset: {$file}");
        }

        // JS engine
        $jsPath = $baseDir . 'js/astraea-admin.js';
        self::assertTrue(file_exists($jsPath), 'astraea-admin.js must exist');
        self::assertTrue(filesize($jsPath) > 500, 'astraea-admin.js must not be empty');

        $shellContent = file_get_contents($baseDir . 'css/astraea-shell.css');
        $dashboardContent = file_get_contents($baseDir . 'css/astraea-dashboard.css');
        $adminScript = file_get_contents($jsPath);
        self::assertStringContains('@media screen and (max-width: 782px)', (string) $shellContent, 'Shell must implement the WordPress mobile breakpoint');
        self::assertStringContains('margin-left: 0 !important', (string) $shellContent, 'Mobile shell must release the desktop content offset');
        $desktopShell = strstr((string) $shellContent, '@media screen and (max-width: 960px)', true);
        self::assertFalse(str_contains((string) $desktopShell, 'body.auto-fold #wpcontent'), 'Desktop auto-fold must retain the full sidebar content offset');
        self::assertStringContains('margin-left: var(--astraea-sidebar-width, 216px) !important', (string) $shellContent, 'Desktop content must align to the sidebar width token');
        self::assertStringContains('grid-template-columns: minmax(0, 1fr)', (string) $dashboardContent, 'Dashboard must collapse to one bounded column');
        self::assertStringContains('.astraea-signal-strip', (string) $dashboardContent, 'Security HUD signal strip styling must be present');
        self::assertFalse(str_contains((string) $adminScript, 'innerHTML ='), 'Admin runtime must not render dynamic content through innerHTML');
        $distributionVersion = defined('ASTRAEA_VERSION') ? (string)ASTRAEA_VERSION : \Astraea\Version::VERSION;
        self::assertTrue(str_starts_with(AdminUIBootstrap::assetVersion('css/astraea-shell.css'), $distributionVersion . '.'), 'Local assets must use file modification cache busting');
        self::assertEquals($distributionVersion, AdminUIBootstrap::assetVersion('../escape.css'), 'Asset version resolver must reject traversal paths');
    }

    private static function testComponentPreview(): void {
        self::assertEquals('astraea-components-preview', \Astraea\AdminUI\Components\ComponentPreview::SLUG);
        ob_start();
        \Astraea\AdminUI\Components\ComponentPreview::renderShowcase();
        $output = ob_get_clean();
        self::assertStringContains('Astraea Glass Component Showcase', (string) $output);
        self::assertStringContains('astraea-btn-primary', (string) $output);
        self::assertStringContains('ast-glass-l1', (string) $output);
    }

    private static function testAccessibilityAndMotionTokens(): void {
        $tokensPath = dirname(__DIR__) . '/astraea-core/AdminUI/assets/css/astraea-tokens.css';
        $tokensContent = file_get_contents($tokensPath);
        self::assertStringContains('prefers-reduced-motion', (string) $tokensContent, 'Reduced motion media query must be present');
        self::assertStringContains('--ast-transition-fast: 0ms', (string) $tokensContent, 'Reduced motion must nullify transitions');
        self::assertStringContains('--astraea-color-primary', (string) $tokensContent, 'Astraea primary color token must be defined');

        $glassPath = dirname(__DIR__) . '/astraea-core/AdminUI/assets/css/astraea-glass.css';
        $glassContent = file_get_contents($glassPath);
        self::assertStringContains('.astraea-glass-surface-l1', (string) $glassContent, 'L1 class alias must exist');
        self::assertStringContains('@supports not (backdrop-filter', (string) $glassContent, 'Graceful fallback for non-backdrop-filter must exist');
    }
}
