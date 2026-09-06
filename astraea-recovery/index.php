<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

/**
 * AstraeaOS WP Autonomous Recovery Environment.
 *
 * Isolated, minimal recovery console operating independently of
 * WordPress themes, plugins, and full bootstrap sequence.
 */

// Strict local error handling and security baseline
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

$coreDir = dirname(__DIR__) . '/astraea-core';
if (!is_dir($coreDir)) {
    http_response_code(500);
    exit('Astraea Core directory unavailable.');
}

require_once $coreDir . '/Version.php';
require_once $coreDir . '/Bootstrap/Autoloader.php';
\Astraea\Bootstrap\Autoloader::register($coreDir);

use Astraea\Recovery\RecoveryController;
use Astraea\Recovery\BootFailureDetector;

if (!function_exists('esc_html')) {
    function esc_html(?string $text): string {
        return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$message = '';
$error = '';

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = (string)($_POST['recovery_key'] ?? '');
    if (RecoveryController::authenticate($key)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid recovery key or authentication credentials.';
    }
}

if ($action === 'logout') {
    RecoveryController::destroySession();
    header('Location: index.php');
    exit;
}

$isAuth = RecoveryController::isAuthenticated();

if ($isAuth && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = (string)($_POST['csrf_token'] ?? '');
    if (!RecoveryController::verifyCsrfToken($submittedCsrf)) {
        $error = 'Security check failed: Invalid or missing CSRF recovery token.';
    } elseif ($action === 'disable_plugins') {
        try {
            $res = RecoveryController::emergencyDisablePlugins();
            $message = 'Active plugins deactivated successfully.';
        } catch (\Throwable $e) {
            $error = 'Failed to deactivate plugins: ' . $e->getMessage();
        }
    } elseif ($action === 'disable_module') {
        $mod = (string)($_POST['module_id'] ?? '');
        try {
            RecoveryController::emergencyDisableModule($mod);
            $message = sprintf('Module "%s" disabled.', htmlspecialchars($mod, ENT_QUOTES, 'UTF-8'));
        } catch (\Throwable $e) {
            $error = 'Failed to disable module: ' . $e->getMessage();
        }
    } elseif ($action === 'toggle_maintenance') {
        $enable = (($_POST['enable'] ?? '0') === '1');
        RecoveryController::toggleMaintenanceLock($enable);
        $message = $enable ? 'Maintenance lock enabled.' : 'Maintenance lock disabled.';
    } elseif ($action === 'reset_boot_counter') {
        BootFailureDetector::reset();
        $message = 'Boot failure counter reset.';
    }
}

$dbHealth = ['status' => 'LOCKED', 'latency_ms' => 0.0];
$integrity = ['status' => 'LOCKED'];
$bootFailures = 0;
$lastFatal = null;

if ($isAuth) {
    $dbHealth = RecoveryController::checkDatabase();
    $integrity = RecoveryController::checkIntegrity();
    $bootFailures = BootFailureDetector::getFailureCount();
    $lastFatal = BootFailureDetector::getLastFatal();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AstraeaOS Recovery Console</title>
    <style>
        :root {
            --bg-base: #030712;
            --bg-surface: rgba(15, 23, 42, 0.75);
            --border-line: rgba(255, 255, 255, 0.08);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --accent-cyan: #38bdf8;
            --accent-green: #4ade80;
            --accent-red: #f87171;
            --accent-amber: #facc15;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; }
        body { background-color: var(--bg-base); color: var(--text-main); min-height: 100vh; padding: 40px 20px; display: flex; justify-content: center; align-items: flex-start; }
        .console-wrap { width: 100%; max-width: 860px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .logo { font-size: 20px; font-weight: 700; color: var(--accent-cyan); letter-spacing: -0.02em; }
        .glass-card { background: var(--bg-surface); backdrop-filter: blur(16px); border: 1px solid var(--border-line); border-radius: 12px; padding: 24px; margin-bottom: 20px; }
        .metric-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px; }
        .metric-box { background: rgba(30, 41, 59, 0.5); border: 1px solid var(--border-line); border-radius: 8px; padding: 16px; }
        .metric-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); font-weight: 600; margin-bottom: 6px; }
        .metric-val { font-size: 18px; font-weight: 700; font-family: monospace; }
        .badge-healthy { color: var(--accent-green); }
        .badge-warn { color: var(--accent-amber); }
        .badge-critical { color: var(--accent-red); }
        .btn { display: inline-block; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; text-decoration: none; }
        .btn-primary { background: #0284c7; color: #fff; }
        .btn-danger { background: rgba(239, 68, 68, 0.2); color: var(--accent-red); border: 1px solid rgba(239, 68, 68, 0.4); }
        .btn-subtle { background: rgba(255, 255, 255, 0.08); color: var(--text-main); }
        .input-field { width: 100%; padding: 10px 14px; background: rgba(0, 0, 0, 0.4); border: 1px solid var(--border-line); border-radius: 6px; color: #fff; font-family: monospace; font-size: 14px; margin-bottom: 12px; }
        .msg-box { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 13px; }
        .msg-success { background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(74, 222, 128, 0.3); color: var(--accent-green); }
        .msg-error { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(248, 113, 113, 0.3); color: var(--accent-red); }
    </style>
</head>
<body>
    <div class="console-wrap">
        <div class="header">
            <div>
                <div class="logo">⚡ AstraeaOS Emergency Recovery</div>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">Core Distribution v<?php echo esc_html(\Astraea\Version::VERSION); ?></div>
            </div>
            <?php if ($isAuth): ?>
                <a href="index.php?action=logout" class="btn btn-subtle" style="font-size: 12px;">Lock Console</a>
            <?php endif; ?>
        </div>

        <?php if ($message !== ''): ?>
            <div class="msg-box msg-success"><?php echo esc_html($message); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="msg-box msg-error"><?php echo esc_html($error); ?></div>
        <?php endif; ?>

        <?php if ($isAuth): ?>
        <div class="metric-grid">
            <div class="metric-box">
                <div class="metric-label">Database Health</div>
                <div class="metric-val <?php echo $dbHealth['status'] === 'HEALTHY' ? 'badge-healthy' : 'badge-critical'; ?>">
                    <?php echo esc_html($dbHealth['status']); ?> (<?php echo esc_html((string)$dbHealth['latency_ms']); ?> ms)
                </div>
            </div>
            <div class="metric-box">
                <div class="metric-label">Core Integrity</div>
                <div class="metric-val <?php echo $integrity['status'] === 'VERIFIED' ? 'badge-healthy' : 'badge-critical'; ?>">
                    <?php echo esc_html($integrity['status']); ?>
                </div>
            </div>
            <div class="metric-box">
                <div class="metric-label">Boot Failure Loop Count</div>
                <div class="metric-val <?php echo $bootFailures >= 3 ? 'badge-critical' : ($bootFailures > 0 ? 'badge-warn' : 'badge-healthy'); ?>">
                    <?php echo (int)$bootFailures; ?> / 3
                </div>
            </div>
        </div>

        <?php if ($lastFatal !== null): ?>
            <div class="glass-card" style="border-color: rgba(239, 68, 68, 0.4);">
                <h4 style="color: var(--accent-red); margin-bottom: 8px;">Last Recorded Fatal Error</h4>
                <div style="font-family: monospace; font-size: 12px; color: #cbd5e1; background: rgba(0,0,0,0.5); padding: 12px; border-radius: 6px;">
                    <?php echo esc_html($lastFatal['message'] ?? 'Unknown'); ?> (in <?php echo esc_html($lastFatal['file'] ?? ''); ?>:<?php echo (int)($lastFatal['line'] ?? 0); ?>)
                </div>
            </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (!$isAuth): ?>
            <div class="glass-card">
                <h3 style="margin-bottom: 12px; font-size: 16px;">Authenticate Emergency Console</h3>
                <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">
                    Enter your 256-bit ThroneGuard Master recovery key to unlock emergency recovery controls.
                </p>
                <form method="post" action="index.php">
                    <input type="hidden" name="action" value="login">
                    <input type="password" name="recovery_key" class="input-field" placeholder="Enter recovery key..." required autocomplete="off">
                    <button type="submit" class="btn btn-primary">Unlock Recovery Console</button>
                </form>
            </div>
        <?php else: ?>
            <div class="glass-card">
                <h3 style="margin-bottom: 16px; font-size: 16px;">Emergency Restoration Actions</h3>
                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <form method="post" action="index.php" style="display: flex; justify-content: space-between; align-items: center;">
                        <input type="hidden" name="action" value="disable_plugins">
                        <input type="hidden" name="csrf_token" value="<?php echo esc_html(RecoveryController::generateCsrfToken()); ?>">
                        <div>
                            <div style="font-weight: 600; font-size: 14px;">Deactivate All WordPress Plugins</div>
                            <div style="font-size: 12px; color: var(--text-muted);">Neutralizes third-party plugin crashes without deleting plugin files.</div>
                        </div>
                        <button type="submit" class="btn btn-danger" onclick="return confirm('Deactivate all active WordPress plugins in the database?');">
                            Deactivate Plugins
                        </button>
                    </form>

                    <hr style="border: 0; border-top: 1px solid var(--border-line);">

                    <form method="post" action="index.php" style="display: flex; justify-content: space-between; align-items: center;">
                        <input type="hidden" name="action" value="toggle_maintenance">
                        <input type="hidden" name="csrf_token" value="<?php echo esc_html(RecoveryController::generateCsrfToken()); ?>">
                        <input type="hidden" name="enable" value="<?php echo file_exists(dirname(__DIR__) . '/.maintenance') ? '0' : '1'; ?>">
                        <div>
                            <div style="font-weight: 600; font-size: 14px;">Maintenance Lock Gate</div>
                            <div style="font-size: 12px; color: var(--text-muted);">Puts site in HTTP 503 maintenance mode to protect database during repairs.</div>
                        </div>
                        <button type="submit" class="btn btn-subtle">
                            <?php echo file_exists(dirname(__DIR__) . '/.maintenance') ? 'Disable Maintenance Lock' : 'Enable Maintenance Lock'; ?>
                        </button>
                    </form>

                    <hr style="border: 0; border-top: 1px solid var(--border-line);">

                    <form method="post" action="index.php" style="display: flex; justify-content: space-between; align-items: center;">
                        <input type="hidden" name="action" value="reset_boot_counter">
                        <input type="hidden" name="csrf_token" value="<?php echo esc_html(RecoveryController::generateCsrfToken()); ?>">
                        <div>
                            <div style="font-weight: 600; font-size: 14px;">Reset Boot Failure Loop Counter</div>
                            <div style="font-size: 12px; color: var(--text-muted);">Clears the incomplete boot tracker once repairs have been performed.</div>
                        </div>
                        <button type="submit" class="btn btn-subtle">Reset Counter</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
