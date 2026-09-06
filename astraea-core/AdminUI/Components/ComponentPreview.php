<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\AdminUI\Components;

/**
 * Astraea Component Preview / Story Showcase.
 *
 * Provides an internal administrative design preview to verify
 * all Astraea Glass UI tokens, buttons, inputs, pills, cards, and modal components.
 * Gated strictly to administrators (`manage_options`).
 *
 * @package Astraea\AdminUI\Components
 */
final class ComponentPreview {

    public const SLUG = 'astraea-components-preview';

    /**
     * Register admin menu page for Component Preview.
     */
    public static function init(): void {
        add_action('admin_menu', [self::class, 'registerMenuPage']);
    }

    /**
     * Register submenu under Tools.
     */
    public static function registerMenuPage(): void {
        add_submenu_page(
            'tools.php',
            'Astraea Glass Component Showcase',
            'Astraea Components',
            'manage_options',
            self::SLUG,
            [self::class, 'renderShowcase']
        );
    }

    /**
     * Render the Component Showcase UI.
     */
    public static function renderShowcase(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.'));
        }
        ?>
        <div class="wrap astraea-admin-wrap">
            <div class="astraea-page-header">
                <div class="astraea-page-brand">
                    <img src="<?php echo esc_url(function_exists('site_url') ? site_url('/astraea-core/AdminUI/assets/img/astraea-brand-2026.png') : content_url('../astraea-core/AdminUI/assets/img/astraea-brand-2026.png')); ?>" alt="AstraeaOS" class="astraea-page-logo" width="48" height="48" style="width:48px;height:48px;object-fit:contain;" />
                    <div>
                        <h1 class="astraea-page-title">Astraea Glass Component Showcase</h1>
                        <p class="astraea-page-sub">Internal design system verification suite &bull; Standard 2.1 DIAMANT VGT SUPREME</p>
                    </div>
                </div>
            </div>

            <div class="astraea-showcase-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; margin-top: 24px;">
                <!-- 1. Buttons -->
                <div class="astraea-cc-card astraea-glass-surface-l1">
                    <div class="card-header">
                        <div class="card-title">
                            <h3>Buttons</h3>
                        </div>
                    </div>
                    <div class="card-body" style="display: flex; flex-direction: column; gap: 12px;">
                        <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                            <button type="button" class="astraea-btn astraea-btn-primary">Primary Glass</button>
                            <button type="button" class="astraea-btn astraea-btn-ghost">Ghost / Secondary</button>
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                            <button type="button" class="astraea-btn astraea-btn-sm astraea-btn-primary">Small Primary</button>
                            <button type="button" class="astraea-btn astraea-btn-sm astraea-btn-ghost">Small Ghost</button>
                            <button type="button" class="button button-primary">WP Compat Primary</button>
                        </div>
                    </div>
                </div>

                <!-- 2. Status Pills & Badges -->
                <div class="astraea-cc-card astraea-glass-surface-l1">
                    <div class="card-header">
                        <div class="card-title">
                            <h3>Status Pills &amp; Indicators</h3>
                        </div>
                    </div>
                    <div class="card-body" style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
                        <span class="astraea-status-pill secure"><span class="pulse-dot"></span> Secure</span>
                        <span class="astraea-status-pill success">&#x2714; Active</span>
                        <span class="astraea-status-pill info">Info</span>
                        <span class="astraea-status-pill warning">&#x26A0; Attention</span>
                        <span class="astraea-status-pill danger">&#x2715; Critical</span>
                        <span class="astraea-status-pill default">Default</span>
                    </div>
                </div>

                <!-- 3. Form Controls -->
                <div class="astraea-cc-card astraea-glass-surface-l1">
                    <div class="card-header">
                        <div class="card-title">
                            <h3>Form Inputs</h3>
                        </div>
                    </div>
                    <div class="card-body" style="display: flex; flex-direction: column; gap: 12px;">
                        <input type="text" placeholder="Standard text input..." style="width: 100%;" />
                        <select style="width: 100%;">
                            <option>Dropdown option 1</option>
                            <option>Dropdown option 2</option>
                        </select>
                        <div style="display: flex; align-items: center; gap: 16px;">
                            <label style="display: inline-flex; align-items: center;">
                                <input type="checkbox" checked /> Checkbox
                            </label>
                            <label style="display: inline-flex; align-items: center;">
                                <input type="radio" name="sample_radio" checked /> Radio A
                            </label>
                            <label style="display: inline-flex; align-items: center;">
                                <input type="radio" name="sample_radio" /> Radio B
                            </label>
                        </div>
                    </div>
                </div>

                <!-- 4. Glass Depth Tiers -->
                <div class="astraea-cc-card astraea-glass-surface-l1">
                    <div class="card-header">
                        <div class="card-title">
                            <h3>Glass Depth Tiers</h3>
                        </div>
                    </div>
                    <div class="card-body" style="display: flex; flex-direction: column; gap: 10px;">
                        <div class="ast-glass-l1" style="padding: 10px; border-radius: 6px;">
                            <strong>Layer 1 (12px blur)</strong> - Subtle ambient panels
                        </div>
                        <div class="ast-glass-l2" style="padding: 10px; border-radius: 6px;">
                            <strong>Layer 2 (20px blur)</strong> - Shell, sidebar, headers
                        </div>
                        <div class="ast-glass-l3" style="padding: 10px; border-radius: 6px;">
                            <strong>Layer 3 (32px blur)</strong> - Modals, Command Palette
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
