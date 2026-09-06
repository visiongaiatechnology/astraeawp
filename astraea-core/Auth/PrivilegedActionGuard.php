<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Auth;

use Astraea\Exceptions\SecurityException;
use Astraea\Security\SecurityEventManager;

/**
 * Central interactive privileged-action barrier.
 *
 * Automated cron/CLI operations are intentionally not elevated by a browser
 * session. Interactive plugin/theme mutations and high-impact account/security
 * changes require a current-session Step-Up grant.
 */
final class PrivilegedActionGuard {

    public static function init(): void {
        add_filter('upgrader_pre_install', [self::class, 'guardUpgrader'], 0, 2);
        add_action('admin_init', [self::class, 'guardAdminRequest'], 0);
        add_filter('rest_pre_dispatch', [self::class, 'guardRestRequest'], 0, 3);
    }

    public static function guardUpgrader(mixed $response, array $hookExtra): mixed {
        if (is_wp_error($response) || self::isNonInteractiveRuntime()) {
            return $response;
        }

        $type = isset($hookExtra['type']) && is_string($hookExtra['type']) ? sanitize_key($hookExtra['type']) : '';
        $action = isset($hookExtra['action']) && is_string($hookExtra['action']) ? sanitize_key($hookExtra['action']) : '';
        if (!in_array($type, ['plugin', 'theme'], true) || !in_array($action, ['install', 'update'], true)) {
            return $response;
        }

        $capability = $type === 'plugin'
            ? ($action === 'install' ? 'install_plugins' : 'update_plugins')
            : ($action === 'install' ? 'install_themes' : 'update_themes');

        if (!current_user_can($capability)) {
            return $response;
        }

        try {
            StepUpAuthService::guardSensitiveAction(get_current_user_id(), $type . ':' . $action);
            return $response;
        } catch (SecurityException) {
            return new \WP_Error(
                'astraea_step_up_required',
                __('Astraea blocked this privileged change. Re-authenticate in Security → Sessions, then retry.', 'astraeaos')
            );
        }
    }

    public static function guardAdminRequest(): void {
        if (self::isNonInteractiveRuntime() || strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST' || !is_user_logged_in()) {
            return;
        }

        $operation = self::classifyAdminOperation();
        if ($operation === null) {
            return;
        }

        try {
            StepUpAuthService::guardSensitiveAction(get_current_user_id(), $operation);
        } catch (SecurityException) {
            SecurityEventManager::recordOnce(
                SecurityEventManager::SEVERITY_WARNING,
                'Authorization',
                'privileged_action_blocked',
                'A privileged WordPress administration request was blocked pending current-session re-authentication.',
                ['operation' => $operation, 'user_id' => get_current_user_id()],
                30
            );
            wp_die(
                wp_kses_post(
                    sprintf(
                        'Astraea requires current-session re-authentication before this operation. <a href="%s">Open Security &rarr; Sessions</a>.',
                        esc_url(admin_url('index.php?page=astraea-security&tab=sessions'))
                    )
                ),
                esc_html__('Step-Up Authentication Required', 'astraeaos'),
                ['response' => 403]
            );
        }
    }

    private static function classifyAdminOperation(): ?string {
        global $pagenow;
        $page = is_string($pagenow ?? null) ? $pagenow : basename((string)($_SERVER['PHP_SELF'] ?? ''));

        if ($page === 'user-new.php') {
            $role = isset($_POST['role']) && is_string($_POST['role']) ? sanitize_key(wp_unslash($_POST['role'])) : '';
            return $role === 'administrator' ? 'administrator:create' : null;
        }

        if ($page === 'user-edit.php' || $page === 'profile.php') {
            $role = isset($_POST['role']) && is_string($_POST['role']) ? sanitize_key(wp_unslash($_POST['role'])) : '';
            if ($role === 'administrator') {
                return 'administrator:promote_or_update';
            }

            $targetUserId = $page === 'profile.php'
                ? get_current_user_id()
                : (isset($_POST['user_id']) ? absint($_POST['user_id']) : 0);
            if ($targetUserId > 0 && isset($_POST['email']) && is_string($_POST['email'])) {
                $target = get_userdata($targetUserId);
                $newEmail = sanitize_email((string)wp_unslash($_POST['email']));
                if ($target instanceof \WP_User && in_array('administrator', $target->roles, true)
                    && $newEmail !== '' && !hash_equals((string)$target->user_email, $newEmail)) {
                    return 'administrator:email_change';
                }
            }
        }

        if (in_array($page, ['plugin-editor.php', 'theme-editor.php'], true)) {
            return $page === 'plugin-editor.php' ? 'plugin:code_edit' : 'theme:code_edit';
        }

        if ($page === 'update.php') {
            $action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
            if (in_array($action, ['delete-plugin', 'delete-selected'], true)) {
                return 'plugin:delete';
            }
            if ($action === 'delete-theme') {
                return 'theme:delete';
            }
        }

        if ($page === 'options.php' && isset($_POST['admin_email'])) {
            $newEmail = sanitize_email((string)wp_unslash($_POST['admin_email']));
            $oldEmail = (string)get_option('admin_email', '');
            if ($newEmail !== '' && !hash_equals($oldEmail, $newEmail)) {
                return 'site:admin_email_change';
            }
        }

        if ($page === 'admin-post.php') {
            $action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
            if (in_array($action, ['vis_titan_policy_action'], true)) {
                return 'security_policy:change';
            }
        }

        return null;
    }

    public static function guardRestRequest(mixed $result, mixed $server, mixed $request): mixed {
        if (!is_user_logged_in() || self::isNonInteractiveRuntime()) {
            return $result;
        }

        if (!is_object($request) || !method_exists($request, 'get_method') || !method_exists($request, 'get_route')) {
            return $result;
        }

        $method = strtoupper((string)$request->get_method());
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $result;
        }

        $route = (string)$request->get_route();
        $operation = self::classifyRestOperation($route, $request);
        if ($operation === null) {
            return $result;
        }

        try {
            StepUpAuthService::guardSensitiveAction(get_current_user_id(), $operation);
            return $result;
        } catch (SecurityException) {
            SecurityEventManager::recordOnce(
                SecurityEventManager::SEVERITY_WARNING,
                'Authorization',
                'privileged_rest_action_blocked',
                'A privileged WordPress REST API request was blocked pending current-session re-authentication.',
                ['operation' => $operation, 'user_id' => get_current_user_id(), 'route' => $route],
                30
            );

            return new \WP_Error(
                'rest_step_up_required',
                __('Astraea blocked this privileged REST mutation. Re-authenticate with Step-Up in Security → Sessions.', 'astraeaos'),
                ['status' => 403]
            );
        }
    }

    private static function classifyRestOperation(string $route, object $request): ?string {
        if (str_starts_with($route, '/wp/v2/users')) {
            $params = method_exists($request, 'get_params') ? (array)$request->get_params() : [];
            $roles = $params['roles'] ?? [];
            if (is_string($roles) && $roles === 'administrator') {
                return 'administrator:rest_mutation';
            }
            if (is_array($roles) && in_array('administrator', $roles, true)) {
                return 'administrator:rest_mutation';
            }
            if (isset($params['email']) && is_string($params['email'])) {
                $targetId = isset($params['id']) ? (int)$params['id'] : 0;
                if ($targetId > 0 && function_exists('get_userdata')) {
                    $u = get_userdata($targetId);
                    if ($u instanceof \WP_User && in_array('administrator', $u->roles, true)) {
                        return 'administrator:email_change';
                    }
                }
            }
        }

        if (str_starts_with($route, '/wp/v2/plugins') || str_starts_with($route, '/wp/v2/themes')) {
            return 'rest:plugin_theme_mutation';
        }

        if (str_starts_with($route, '/wp/v2/settings')) {
            return 'rest:settings_mutation';
        }

        return null;
    }

    private static function isNonInteractiveRuntime(): bool {
        return (defined('WP_CLI') && WP_CLI) || (function_exists('wp_doing_cron') && wp_doing_cron());
    }
}
