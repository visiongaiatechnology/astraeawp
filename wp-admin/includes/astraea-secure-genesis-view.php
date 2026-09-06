<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

use Astraea\Installer\SecureGenesis;

if (!defined('ABSPATH')) exit;

function astraea_genesis_status_class(string $status): string {
    return match ($status) {
        'PASS' => 'is-pass',
        'WARN' => 'is-warn',
        default => 'is-fail',
    };
}

function astraea_display_secure_genesis_form(?string $error = null): void {
    global $wpdb;
    $userTable = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->users))) !== null);
    $preflight = SecureGenesis::preflight();
    $blocked = SecureGenesis::preflightBlocksInstallation();

    $weblogTitle = isset($_POST['weblog_title']) ? trim(wp_unslash((string)$_POST['weblog_title'])) : '';
    $userName = isset($_POST['user_name']) ? trim(wp_unslash((string)$_POST['user_name'])) : '';
    $adminEmail = isset($_POST['admin_email']) ? trim(wp_unslash((string)$_POST['admin_email'])) : '';
    $blogPublic = isset($_POST['blog_public']) ? (int)$_POST['blog_public'] : 1;
    $postedRecovery = isset($_POST['astraea_recovery_key']) && is_string($_POST['astraea_recovery_key']) ? wp_unslash($_POST['astraea_recovery_key']) : '';
    $recoveryKey = SecureGenesis::validateRecoveryKey($postedRecovery) ? $postedRecovery : SecureGenesis::generateRecoveryKey();
    $profile = isset($_POST['astraea_genesis']['security_profile']) && is_string($_POST['astraea_genesis']['security_profile'])
        ? sanitize_key(wp_unslash($_POST['astraea_genesis']['security_profile']))
        : 'balanced';

    if ($error !== null) {
        echo '<div class="astraea-genesis-alert is-error" role="alert"><strong>Validation failed.</strong><span>' . wp_kses_post($error) . '</span></div>';
    }
    ?>
    <div class="astraea-genesis-head">
        <span class="astraea-kicker">SECURE GENESIS</span>
        <h1><?php esc_html_e('Build the security boundary before the first login.'); ?></h1>
        <p><?php esc_html_e('AstraeaOS WP compiles identity, privilege separation and GeDefense policy as part of installation — not as an afterthought.'); ?></p>
    </div>

    <div class="astraea-genesis-progress" aria-label="Secure Genesis progress">
        <?php
        $steps = ['Preflight','Site','Master','Profile','ThroneGuard','GeDefense','Network','Privacy','Compile','Launch'];
        foreach ($steps as $i => $label) {
            printf('<div class="astraea-progress-node%s" data-progress-node="%d"><span>%02d</span><small>%s</small></div>', $i === 0 ? ' is-active' : '', $i + 1, $i + 1, esc_html($label));
        }
        ?>
    </div>

    <form id="astraea-secure-genesis" method="post" action="install.php?step=2" novalidate="novalidate">
        <section class="astraea-genesis-step is-active" data-genesis-step="1">
            <div class="astraea-step-title"><span>01</span><div><h2>System Preflight</h2><p>Measured runtime evidence. Unknown or unsafe states are never painted green.</p></div></div>
            <div class="astraea-preflight-grid">
                <?php foreach ($preflight as $check): ?>
                    <article class="astraea-check <?php echo esc_attr(astraea_genesis_status_class($check['status'])); ?>">
                        <div><strong><?php echo esc_html($check['label']); ?></strong><small><?php echo esc_html($check['detail']); ?></small></div>
                        <span><?php echo esc_html($check['status']); ?></span>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ($blocked): ?>
                <div class="astraea-genesis-alert is-error"><strong>Blocking requirement failure</strong><span>Resolve all FAIL checks marked as required before Astraea can install.</span></div>
            <?php endif; ?>
        </section>

        <section class="astraea-genesis-step" data-genesis-step="2">
            <div class="astraea-step-title"><span>02</span><div><h2>Site Identity</h2><p>Core site metadata and the account that will become the protected Astraea Master.</p></div></div>
            <div class="astraea-field-grid">
                <label class="astraea-field"><span>Site title</span><input name="weblog_title" type="text" required value="<?php echo esc_attr($weblogTitle); ?>"></label>
                <label class="astraea-field"><span>Administrator email</span><input name="admin_email" type="email" required value="<?php echo esc_attr($adminEmail); ?>"></label>
                <?php if ($userTable): ?>
                    <input name="user_name" type="hidden" value="admin">
                    <div class="astraea-field"><span>Username</span><div class="astraea-readonly">Existing user table detected</div></div>
                <?php else: ?>
                    <label class="astraea-field"><span>Master username</span><input name="user_name" type="text" id="user_login" required value="<?php echo esc_attr(sanitize_user($userName, true)); ?>"></label>
                <?php endif; ?>
                <label class="astraea-field astraea-field-wide"><span>Search visibility</span><span class="astraea-toggle-row"><input name="blog_public" type="checkbox" value="0" <?php checked(0, $blogPublic); ?>><span>Discourage search engines during initial hardening</span></span></label>
            </div>
        </section>

        <section class="astraea-genesis-step" data-genesis-step="3">
            <div class="astraea-step-title"><span>03</span><div><h2>Master Identity</h2><p>Your first account becomes a ThroneGuard-protected Master, separated from normal administrators.</p></div></div>
            <?php if (!$userTable): ?>
            <div class="astraea-field-grid">
                <label class="astraea-field astraea-field-wide"><span>Master password</span>
                    <?php $initialPassword = isset($_POST['admin_password']) ? wp_unslash((string)$_POST['admin_password']) : wp_generate_password(20); ?>
                    <div class="wp-pwd"><input type="password" name="admin_password" id="pass1" class="regular-text" autocomplete="new-password" spellcheck="false" data-reveal="1" data-pw="<?php echo esc_attr($initialPassword); ?>" aria-describedby="pass-strength-result"><div id="pass-strength-result" aria-live="polite"></div><button type="button" class="button wp-hide-pw user-new-password-toggle hide-if-no-js" data-start-masked="1" data-toggle="0"><span class="dashicons dashicons-hidden"></span><span class="text">Hide</span></button></div>
                </label>
                <label class="astraea-field astraea-field-wide hide-if-js"><span>Repeat password</span><input type="password" name="admin_password2" id="pass2" autocomplete="new-password"></label>
                <div class="astraea-password-policy astraea-field-wide"><strong>Secure Genesis password policy</strong><small>Minimum 16 characters. Long passphrases are welcome. Whitespace remains credential-significant. Username and email account name are rejected inside the password.</small></div>
            </div>
            <?php endif; ?>
            <div class="astraea-master-card">
                <div><span class="astraea-kicker">PRIVILEGE BOUNDARY</span><h3>Astraea Master</h3><p>Normal administrators can be stripped of plugin, theme, user-elevation and filesystem capabilities. The Master retains the sovereign boundary.</p></div>
                <span class="astraea-state-pill">THRONEGUARD</span>
            </div>
        </section>

        <section class="astraea-genesis-step" data-genesis-step="4">
            <div class="astraea-step-title"><span>04</span><div><h2>Security Profile</h2><p>Choose a defensible baseline. Maximum intentionally trades compatibility for confinement.</p></div></div>
            <div class="astraea-profile-grid">
                <?php
                $profiles = [
                    'balanced' => ['Balanced','Recommended','Aegis learning, Cerberus, Prometheus, Titan, Airlock, ThroneGuard and outbound audit.'],
                    'hardened' => ['Hardened','Strict','Strict Aegis, Morpheus, application-password lockdown and stronger Titan controls.'],
                    'maximum' => ['Maximum','Compatibility impact','Morpheus enforcement, REST restriction and application lockdown. Best for controlled workloads.'],
                    'custom' => ['Custom','Expert','Explicitly select the kernel modules and network restrictions below.'],
                ];
                foreach ($profiles as $key => [$name,$badge,$desc]): ?>
                <label class="astraea-profile-card">
                    <input type="radio" name="astraea_genesis[security_profile]" value="<?php echo esc_attr($key); ?>" <?php checked($profile, $key); ?>>
                    <span class="astraea-profile-radio"></span>
                    <strong><?php echo esc_html($name); ?></strong><em><?php echo esc_html($badge); ?></em><small><?php echo esc_html($desc); ?></small>
                </label>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="astraea-genesis-step" data-genesis-step="5">
            <div class="astraea-step-title"><span>05</span><div><h2>ThroneGuard Recovery</h2><p>This 256-bit recovery key is shown before installation and is never stored in plaintext by Astraea.</p></div></div>
            <div class="astraea-recovery-card">
                <div class="astraea-recovery-head"><div><span class="astraea-kicker">ONE-TIME RECOVERY KEY</span><h3>Store this offline.</h3></div><span class="astraea-state-pill is-warn">SHOW ONCE</span></div>
                <code id="astraea-recovery-key"><?php echo esc_html($recoveryKey); ?></code>
                <input type="hidden" name="astraea_recovery_key" value="<?php echo esc_attr($recoveryKey); ?>">
                <div class="astraea-recovery-actions"><button class="button" type="button" data-copy-recovery>Copy</button><button class="button" type="button" data-download-recovery>Download .txt</button></div>
                <label class="astraea-toggle-row astraea-critical-confirm"><input type="checkbox" name="astraea_recovery_ack" value="1" required><span>I have stored the ThroneGuard recovery key safely.</span></label>
            </div>
        </section>

        <section class="astraea-genesis-step" data-genesis-step="6">
            <div class="astraea-step-title"><span>06</span><div><h2>GeDefense Matrix</h2><p>Custom profile controls. Other profiles compile a validated preset and ignore these switches.</p></div></div>
            <div class="astraea-module-grid" data-custom-security>
                <?php
                $modules = [
                    'aegis'=>'Aegis DPI / WAF','cerberus'=>'Cerberus rate & ban perimeter','prometheus'=>'Prometheus behavioral monitoring','titan'=>'Titan HTTP hardening',
                    'morpheus'=>'Morpheus RASP','morpheus_enforce'=>'Morpheus enforcement','nemesis'=>'Nemesis integrity response','styx'=>'Styx outbound control',
                    'airlock'=>'Airlock upload security',
                ];
                foreach ($modules as $key=>$label): ?>
                    <label class="astraea-module-toggle"><input type="checkbox" name="astraea_genesis[<?php echo esc_attr($key); ?>]" value="1" <?php checked(!in_array($key,['hades','morpheus_enforce'],true)); ?>><span><strong><?php echo esc_html($label); ?></strong><small>Kernel-native first-party control</small></span></label>
                <?php endforeach; ?>
                <div class="astraea-module-toggle astraea-mandatory-module"><input type="checkbox" checked disabled><span><strong>ThroneGuard + Master boundary</strong><small>Mandatory in Secure Genesis: Master role, admin hardening and recovery lock cannot be disabled during first installation.</small></span></div>
                <div class="astraea-module-toggle astraea-deferred-module"><input type="checkbox" disabled><span><strong>Hades stealth routing</strong><small>Deferred until after the first verified login to prevent installer lockout. Enable it later from GeDefense when routes are known-good.</small></span></div>
            </div>
        </section>

        <section class="astraea-genesis-step" data-genesis-step="7">
            <div class="astraea-step-title"><span>07</span><div><h2>Network Surface</h2><p>Reduce legacy entry points without pretending every workload has the same compatibility requirements.</p></div></div>
            <div class="astraea-network-list">
                <?php
                $network = [
                    'block_xmlrpc'=>['Block XML-RPC','LOW','Recommended unless remote publishing requires it.',true],
                    'block_rest'=>['Restrict REST API','HIGH','Can break Gutenberg, WooCommerce and external applications.',false],
                    'disable_feeds'=>['Disable feeds','MEDIUM','Disable RSS/Atom if the site does not publish feeds.',false],
                    'hide_version'=>['Suppress version disclosure','LOW','Removes passive version hints.',true],
                    'disable_app_passwords'=>['Disable Application Passwords','MEDIUM','Recommended when no API clients depend on them.',true],
                ];
                foreach ($network as $key=>[$name,$impact,$desc,$checked]): ?>
                    <label class="astraea-network-row"><input type="checkbox" name="astraea_genesis[<?php echo esc_attr($key); ?>]" value="1" <?php checked($checked); ?>><span><strong><?php echo esc_html($name); ?></strong><small><?php echo esc_html($desc); ?></small></span><em class="impact-<?php echo strtolower($impact); ?>"><?php echo esc_html($impact); ?> IMPACT</em></label>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="astraea-genesis-step" data-genesis-step="8">
            <div class="astraea-step-title"><span>08</span><div><h2>Privacy & Communications</h2><p>First-party infrastructure starts with privacy-safe defaults. Mail credentials can be configured after the first login.</p></div></div>
            <div class="astraea-option-stack">
                <label class="astraea-network-row"><input type="checkbox" name="astraea_genesis[vlp]" value="1" checked><span><strong>VLP Light</strong><small>Consent engine, DOM gatekeeper and privacy scanner.</small></span><em class="impact-low">KERNEL</em></label>
                <label class="astraea-network-row"><input type="checkbox" name="astraea_genesis[dattrack]" value="1" checked><span><strong>Dattrack Light</strong><small>Local analytics remains gated behind statistics consent.</small></span><em class="impact-low">LOCAL</em></label>
                <label class="astraea-network-row"><input type="checkbox" name="astraea_genesis[mail_later]" value="1" checked><span><strong>Astraea Mail Gateway</strong><small>Configure SMTP/XOAUTH2 after the first login. No credentials are requested by the installer.</small></span><em class="impact-low">READY</em></label>
            </div>
            <div class="astraea-final-review"><span class="astraea-kicker">READY TO COMPILE</span><h3>Astraea will install the core, provision the Master boundary, persist the selected GeDefense policy, apply anti-lockout allowlisting and verify the resulting state.</h3><p>No security check is reported as PASS unless its post-install verification succeeds.</p></div>
        </section>

        <div class="astraea-genesis-nav">
            <button type="button" class="button astraea-back" data-genesis-back disabled>Back</button>
            <div class="astraea-nav-spacer"></div>
            <button type="button" class="button button-primary astraea-next" data-genesis-next <?php disabled($blocked); ?>>Continue</button>
            <button type="submit" class="button button-primary astraea-install-submit" data-genesis-submit hidden <?php disabled($blocked); ?>>Compile & Install AstraeaOS WP</button>
        </div>
        <input type="hidden" name="language" value="<?php echo isset($_REQUEST['language']) ? esc_attr((string)$_REQUEST['language']) : ''; ?>">
    </form>
    <?php
}

function astraea_render_genesis_recovery_resume(?string $error = null): void {
    if ($error !== null) {
        echo '<div class="astraea-genesis-alert is-error" role="alert"><strong>Recovery key required.</strong><span>' . esc_html($error) . '</span></div>';
    }
    ?>
    <div class="astraea-genesis-head">
        <span class="astraea-kicker">SECURE GENESIS · RECOVERY</span>
        <h1>Resume the security transaction.</h1>
        <p>The WordPress core is already installed, but ThroneGuard did not commit a verified recovery-key hash. Re-enter the one-time key you stored during installation. Astraea will hash it with Argon2id and continue; the plaintext is never persisted.</p>
    </div>
    <form method="post" action="<?php echo esc_url(admin_url('install.php?step=3')); ?>" class="astraea-recovery-resume-form" autocomplete="off">
        <?php wp_nonce_field('astraea_genesis_recovery_resume'); ?>
        <input type="hidden" name="astraea_genesis_recovery_retry" value="1">
        <label class="astraea-field"><span>ThroneGuard recovery key</span><input type="text" name="astraea_recovery_key" required minlength="48" maxlength="96" spellcheck="false" autocomplete="off" placeholder="ATG-..."></label>
        <label class="astraea-toggle-row astraea-critical-confirm"><input type="checkbox" name="astraea_recovery_ack" value="1" required><span>I am using the recovery key generated by this Secure Genesis transaction.</span></label>
        <div class="astraea-genesis-nav"><div class="astraea-nav-spacer"></div><button type="submit" class="button button-primary">Reprovision & Continue</button></div>
    </form>
    <?php
}

/** @param array<string,array{status:string,detail:string}> $report */
function astraea_render_genesis_report(array $report, string $username): void {
    $failed = false;
    foreach ($report as $item) if (($item['status'] ?? '') === 'FAIL') $failed = true;
    ?>
    <div class="astraea-genesis-head astraea-launch-head">
        <span class="astraea-kicker">09 / 10 — SECURITY COMPILATION</span>
        <h1><?php echo $failed ? 'Secure Genesis requires attention.' : 'Astraea is ready.'; ?></h1>
        <p><?php echo $failed ? 'The core is installed, but Astraea did not mark the installation READY because at least one security verification failed.' : 'The security policy was persisted and verified. A first-boot runtime verification is queued for the next normal Astraea request.'; ?></p>
    </div>
    <div class="astraea-compile-list">
        <?php foreach ($report as $name=>$item): ?>
            <div class="astraea-check <?php echo esc_attr(astraea_genesis_status_class($item['status'] ?? 'FAIL')); ?>"><div><strong><?php echo esc_html(ucwords(str_replace('_',' ',$name))); ?></strong><small><?php echo esc_html($item['detail'] ?? ''); ?></small></div><span><?php echo esc_html($item['status'] ?? 'FAIL'); ?></span></div>
        <?php endforeach; ?>
    </div>
    <div class="astraea-launch-card">
        <span class="astraea-kicker">10 / 10 — VERIFIED LAUNCH</span>
        <h2><?php echo $failed ? 'Installation state: SECURITY_FAILED' : 'Security posture: COMPILED'; ?></h2>
        <p>Master identity: <strong><?php echo esc_html($username); ?></strong></p>
        <?php if (!$failed): ?><a class="button button-primary" href="<?php echo esc_url(wp_login_url()); ?>">Enter AstraeaOS WP</a><?php else: ?><a class="button button-primary" href="<?php echo esc_url(admin_url('install.php?step=3')); ?>">Retry Security Compilation</a><?php endif; ?>
    </div>
    <?php
}
