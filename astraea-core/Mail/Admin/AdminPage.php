<?php
// STATUS: DIAMANT VGT SUPREME

declare(strict_types=1);

namespace Astraea\Mail\Admin;

use Astraea\Auth\StepUpAuthService;
use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\StorageException;
use Astraea\Exceptions\ValidationException;
use Astraea\Mail\Diagnostics\SmtpProbe;
use Astraea\Mail\ProviderRegistry;
use Astraea\Mail\Settings;
use Astraea\Mail\Security\EndpointPolicy;
use Astraea\Mail\SmtpConfig;
use Astraea\Mail\Store\EncryptedConfigStore;
use Astraea\Mail\Transport\DeliveryJournal;
use Astraea\Mail\Transport\TransportManager;
use Astraea\Mail\Transport\OAuthTokenService;
use Astraea\Security\SecurityEventManager;

final class AdminPage
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_post_astraea_mail_save', [self::class, 'save']);
        add_action('admin_post_astraea_mail_probe', [self::class, 'probe']);
        add_action('admin_post_astraea_mail_test', [self::class, 'sendTest']);
        add_action('admin_post_astraea_mail_clear_journal', [self::class, 'clearJournal']);
        add_action('admin_post_astraea_mail_reset_breaker', [self::class, 'resetBreaker']);
    }

    public static function menu(): void
    {
        add_menu_page(
            'Astraea Mail Gateway',
            'Mail Gateway',
            'manage_options',
            'astraea-mail',
            [self::class, 'render'],
            'dashicons-email-alt2',
            58
        );
    }

    public static function assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_astraea-mail') {
            return;
        }
        $base = site_url('/astraea-core/Mail/assets/');
        wp_enqueue_style('astraea-mail-admin', $base . 'css/mail-admin.css', ['astraea-components'], Settings::VERSION);
        wp_enqueue_script('astraea-mail-admin', $base . 'js/mail-admin.js', [], Settings::VERSION, ['strategy' => 'defer', 'in_footer' => true]);
        $publicPresets = [];
        foreach (ProviderRegistry::all() as $id => $provider) {
            $publicPresets[$id] = [
                'label' => (string)$provider['label'],
                'host' => (string)$provider['host'],
                'port' => (int)$provider['port'],
                'encryption' => (string)$provider['encryption'],
                'auth_type' => (string)($provider['auth_type'] ?? 'auto'),
                'oauth_token_url' => (string)($provider['oauth_token_url'] ?? ''),
                'oauth_scope' => (string)($provider['oauth_scope'] ?? ''),
                'note' => (string)$provider['note'],
            ];
        }
        wp_localize_script('astraea-mail-admin', 'AstraeaMailPresets', $publicPresets);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'astraeaos'), '', ['response' => 403]);
        }
        $config = TransportManager::config();
        $configLoadFailure = EncryptedConfigStore::hasLoadFailure();
        $tab = isset($_GET['tab']) ? sanitize_key((string)wp_unslash($_GET['tab'])) : 'overview';
        if (!in_array($tab, ['overview', 'configuration', 'security', 'diagnostics', 'journal'], true)) {
            $tab = 'overview';
        }
        $probe = SmtpProbe::last();
        $journal = DeliveryJournal::recent(30);
        $status = isset($_GET['mail_status']) ? sanitize_key((string)wp_unslash($_GET['mail_status'])) : '';
        ?>
        <div class="wrap astmail-admin">
            <div class="astmail-hero">
                <div>
                    <span class="astmail-kicker">ASTRAEAOS · FIRST-PARTY MAIL TRANSPORT</span>
                    <h1>Astraea Mail Gateway</h1>
                    <p>Zero-external-dependency SMTP routing with encrypted credentials, strict TLS policy, diagnostics and evidence-only delivery telemetry.</p>
                </div>
                <div class="astmail-hero-status">
                    <span class="astmail-state <?php echo $configLoadFailure ? 'warn' : ($config->enabled ? 'ok' : 'off'); ?>"><?php echo $configLoadFailure ? 'CONFIG ERROR' : ($config->enabled ? 'ACTIVE' : 'DISABLED'); ?></span>
                    <small><?php echo esc_html((string)(ProviderRegistry::get($config->provider)['label'] ?? 'Custom SMTP')); ?></small>
                </div>
            </div>
            <?php if ($configLoadFailure): ?><div class="astmail-notice bad">Encrypted SMTP configuration failed authentication/decoding. Mail delivery is fail-closed until the profile is replaced.</div><?php endif; ?>
            <?php self::notice($status); ?>
            <nav class="nav-tab-wrapper astmail-tabs">
                <?php foreach (['overview' => 'Übersicht', 'configuration' => 'Konfiguration', 'security' => 'Security', 'diagnostics' => 'Diagnose', 'journal' => 'Delivery Journal'] as $id => $label): ?>
                    <a class="nav-tab <?php echo $tab === $id ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=astraea-mail&tab=' . $id)); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php
            if ($tab === 'overview') self::overview($config, $probe, $journal);
            elseif ($tab === 'configuration') self::configuration($config);
            elseif ($tab === 'security') self::security($config);
            elseif ($tab === 'diagnostics') self::diagnostics($config, $probe);
            else self::journal($journal);
            ?>
        </div>
        <?php
    }

    private static function overview(SmtpConfig $config, array $probe, array $journal): void
    {
        $last = $journal[0] ?? [];
        $tlsLabel = match ($config->encryption) { 'starttls' => 'STARTTLS', 'smtps' => 'SMTPS', default => 'LOCAL PLAIN' };
        ?>
        <div class="astmail-grid">
            <section class="astmail-panel metric"><span class="astmail-label">TRANSPORT</span><strong><?php echo $config->enabled ? 'SMTP ACTIVE' : 'DISABLED'; ?></strong><p><?php echo esc_html($config->host !== '' ? $config->host . ':' . $config->port : 'Noch nicht konfiguriert'); ?></p></section>
            <section class="astmail-panel metric"><span class="astmail-label">TLS POLICY</span><strong><?php echo esc_html($tlsLabel); ?></strong><p>Peer verification ON · Self-signed OFF · Auto-downgrade OFF</p></section>
            <section class="astmail-panel metric"><span class="astmail-label">CREDENTIAL STORE</span><strong>AEAD ENCRYPTED</strong><p><?php echo Settings::externalPassword() !== '' ? 'Password source: external secret' : ($config->password !== '' ? 'Password source: Astraea encrypted store' : 'No password stored'); ?></p></section>
            <section class="astmail-panel metric"><span class="astmail-label">CIRCUIT BREAKER</span><strong><?php echo DeliveryJournal::circuitBreakerOpen() ? 'COOLDOWN' : 'READY'; ?></strong><p>Repeated transport failures cannot indefinitely stall application requests.</p></section>
        </div>
        <div class="astmail-stack">
            <section class="astmail-panel">
                <div class="astmail-panel-head"><div><h2>Operational state</h2><p>All SMTP-sensitive configuration is stored through the Astraea CryptoService under a dedicated mail domain.</p></div><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=astraea-mail&tab=configuration')); ?>">Konfigurieren</a></div>
                <div class="astmail-facts">
                    <div><span>Provider</span><b><?php echo esc_html((string)(ProviderRegistry::get($config->provider)['label'] ?? 'Custom')); ?></b></div>
                    <div><span>Authentication</span><b><?php echo $config->auth ? esc_html(strtoupper($config->authType)) : 'NONE'; ?></b></div>
                    <div><span>Last probe</span><b><?php echo !empty($probe['datetime']) ? esc_html((string)$probe['datetime']) : 'NOT TESTED'; ?></b></div>
                    <div><span>Last delivery</span><b><?php echo !empty($last['datetime']) ? esc_html(strtoupper((string)$last['status']) . ' · ' . (string)$last['datetime']) : 'NO EVENTS'; ?></b></div>
                </div>
            </section>
            <section class="astmail-panel"><h2>Defense in Depth</h2><ul class="astmail-spec"><li>Encrypted full-profile persistence with key-ID-aware Astraea AEAD.</li><li>Strict server certificate and hostname validation for TLS transports.</li><li>Endpoint policy blocks metadata/private targets unless explicitly authorized for a local relay.</li><li>Credentials and message bodies are never persisted in delivery telemetry.</li><li>Configuration, diagnostics and test delivery require current-session Step-Up authentication.</li><li>Invalid configured SMTP never silently falls back to PHP mail.</li></ul></section>
        </div>
        <?php
    }

    private static function configuration(SmtpConfig $config): void
    {
        $providers = ProviderRegistry::all();
        $passwordSource = Settings::externalPassword() !== '' ? 'Externes ASTRAEA_SMTP_PASSWORD aktiv' : ($config->password !== '' ? 'Verschlüsseltes Passwort vorhanden' : 'Kein Passwort gespeichert');
        $dkimSource = Settings::externalDkimPrivateKey() !== '' ? 'Externer ASTRAEA_DKIM_PRIVATE_KEY aktiv' : ($config->dkimPrivateKey !== '' ? 'Verschlüsselter DKIM-Key vorhanden' : 'Kein DKIM-Key gespeichert');
        $oauthClientSecretSource = Settings::externalOAuthClientSecret() !== '' ? 'Externes ASTRAEA_SMTP_OAUTH_CLIENT_SECRET aktiv' : ($config->oauthClientSecret !== '' ? 'Verschlüsseltes Client Secret vorhanden' : 'Kein Client Secret gespeichert');
        $oauthRefreshSource = Settings::externalOAuthRefreshToken() !== '' ? 'Externes ASTRAEA_SMTP_OAUTH_REFRESH_TOKEN aktiv' : ($config->oauthRefreshToken !== '' ? 'Verschlüsseltes Refresh Token vorhanden' : 'Kein Refresh Token gespeichert');
        $oauthAccessSource = Settings::externalOAuthAccessToken() !== '' ? 'Externes ASTRAEA_SMTP_OAUTH_ACCESS_TOKEN aktiv; Refresh-Flow wird übersprungen.' : 'Kein externes Access Token aktiv.';
        ?>
        <section class="astmail-panel astmail-config-panel">
            <div class="astmail-panel-head"><div><h2>SMTP Transport Profile</h2><p>Provider-Presets are convenience defaults. Host, port and encryption remain fully editable for custom infrastructure.</p></div><span class="astmail-encryption-badge">ENCRYPTED AT REST</span></div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="astmail-form" autocomplete="off">
                <?php wp_nonce_field(Settings::SAVE_NONCE); ?>
                <input type="hidden" name="action" value="astraea_mail_save">
                <div class="astmail-form-grid">
                    <label class="astmail-switch wide"><input type="checkbox" name="enabled" value="1" <?php checked($config->enabled); ?>><span>SMTP Gateway aktivieren</span></label>
                    <label>Provider<select name="provider" id="astmail-provider"><?php foreach ($providers as $id => $provider): ?><option value="<?php echo esc_attr($id); ?>" <?php selected($config->provider, $id); ?>><?php echo esc_html((string)$provider['label']); ?></option><?php endforeach; ?></select><small id="astmail-provider-note"><?php echo esc_html((string)(ProviderRegistry::get($config->provider)['note'] ?? '')); ?></small></label>
                    <label>SMTP Host<input id="astmail-host" name="host" value="<?php echo esc_attr($config->host); ?>" required spellcheck="false" autocomplete="off"></label>
                    <label>Port<input id="astmail-port" name="port" type="number" min="1" max="65535" value="<?php echo esc_attr((string)$config->port); ?>" required></label>
                    <label>Transportverschlüsselung<select id="astmail-encryption" name="encryption"><option value="starttls" <?php selected($config->encryption, 'starttls'); ?>>STARTTLS</option><option value="smtps" <?php selected($config->encryption, 'smtps'); ?>>SMTPS / implicit TLS</option><option value="none" <?php selected($config->encryption, 'none'); ?>>None — nur privater/local Relay</option></select></label>
                    <label>Timeout<input name="timeout" type="number" min="3" max="30" value="<?php echo esc_attr((string)$config->timeout); ?>"></label>
                    <label class="astmail-switch"><input type="checkbox" name="auth" value="1" <?php checked($config->auth); ?>><span>SMTP Authentication</span></label>
                    <label>Auth Type<select name="auth_type" id="astmail-auth-type"><option value="auto" <?php selected($config->authType, 'auto'); ?>>AUTO</option><option value="login" <?php selected($config->authType, 'login'); ?>>LOGIN</option><option value="plain" <?php selected($config->authType, 'plain'); ?>>PLAIN</option><option value="xoauth2" <?php selected($config->authType, 'xoauth2'); ?>>XOAUTH2 / Modern Auth</option></select></label>
                    <label>Username<input name="username" value="<?php echo esc_attr($config->username); ?>" autocomplete="username" spellcheck="false"><small><?php echo Settings::externalUsername() !== '' ? 'Externer Username überschreibt diesen Wert.' : ''; ?></small></label>
                    <label>Password<input name="password" type="password" value="" autocomplete="new-password" placeholder="Leer lassen = unverändert"><small><?php echo esc_html($passwordSource); ?></small></label>
                    <label class="astmail-switch"><input type="checkbox" name="clear_password" value="1"><span>Gespeichertes Passwort löschen</span></label>
                    <label class="astmail-switch private"><input type="checkbox" name="allow_private_target" value="1" <?php checked($config->allowPrivateTarget); ?>><span>Private/local SMTP target explizit erlauben</span><small>Nur für selbst kontrollierte Relays. Öffentliche Plaintext-Ziele bleiben gesperrt.</small></label>
                    <label>From E-Mail<input name="from_email" type="email" value="<?php echo esc_attr($config->fromEmail); ?>" autocomplete="off"></label>
                    <label>From Name<input name="from_name" value="<?php echo esc_attr($config->fromName); ?>" autocomplete="off"></label>
                    <label class="astmail-switch"><input type="checkbox" name="force_from_email" value="1" <?php checked($config->forceFromEmail); ?>><span>From E-Mail erzwingen</span></label>
                    <label class="astmail-switch"><input type="checkbox" name="force_from_name" value="1" <?php checked($config->forceFromName); ?>><span>From Name erzwingen</span></label>
                    <label class="astmail-switch"><input type="checkbox" name="return_path" value="1" <?php checked($config->returnPath); ?>><span>Return-Path auf From E-Mail setzen</span></label>
                </div>
                <div class="astmail-subsection astmail-oauth" id="astmail-oauth-section" <?php echo $config->authType === 'xoauth2' ? '' : 'hidden'; ?>>
                    <div><span class="astmail-label">MODERN AUTH</span><h3>XOAUTH2 Token Broker</h3><p>Native OAuth refresh flow via the WordPress HTTP API and bundled PHPMailer OAuth interface. No Composer package or external JavaScript SDK is loaded.</p><small><?php echo esc_html($oauthAccessSource); ?></small></div>
                    <div class="astmail-form-grid">
                        <label class="wide">OAuth Token Endpoint<input id="astmail-oauth-token-url" name="oauth_token_url" type="url" value="<?php echo esc_attr($config->oauthTokenUrl); ?>" autocomplete="off" spellcheck="false" placeholder="https://.../token"><small>HTTPS only. Redirects and private/metadata targets are blocked.</small></label>
                        <label>Client ID<input name="oauth_client_id" value="<?php echo esc_attr($config->oauthClientId); ?>" autocomplete="off" spellcheck="false"></label>
                        <label>Scope<input id="astmail-oauth-scope" name="oauth_scope" value="<?php echo esc_attr($config->oauthScope); ?>" autocomplete="off" spellcheck="false" placeholder="Optional bei Refresh-Token-Flows"></label>
                        <label>Client Secret<input name="oauth_client_secret" type="password" value="" autocomplete="new-password" placeholder="Leer lassen = unverändert"><small><?php echo esc_html($oauthClientSecretSource); ?> · Bei Public Clients optional.</small></label>
                        <label>Refresh Token<input name="oauth_refresh_token" type="password" value="" autocomplete="new-password" placeholder="Leer lassen = unverändert"><small><?php echo esc_html($oauthRefreshSource); ?></small></label>
                        <label class="astmail-switch"><input type="checkbox" name="clear_oauth_client_secret" value="1"><span>Gespeichertes OAuth Client Secret löschen</span></label>
                        <label class="astmail-switch"><input type="checkbox" name="clear_oauth_refresh_token" value="1"><span>Gespeichertes OAuth Refresh Token löschen</span></label>
                    </div>
                </div>
                <div class="astmail-subsection"><div><span class="astmail-label">OPTIONAL</span><h3>DKIM Signing</h3><p>Uses the PHPMailer implementation already bundled with WordPress; no external library is loaded.</p></div><div class="astmail-form-grid"><label class="astmail-switch wide"><input type="checkbox" name="dkim_enabled" value="1" <?php checked($config->dkimEnabled); ?>><span>DKIM-Signatur aktivieren</span></label><label>Domain<input name="dkim_domain" value="<?php echo esc_attr($config->dkimDomain); ?>"></label><label>Selector<input name="dkim_selector" value="<?php echo esc_attr($config->dkimSelector); ?>"></label><label>Identity<input name="dkim_identity" type="email" value="<?php echo esc_attr($config->dkimIdentity); ?>"></label><label class="wide">Private Key<textarea name="dkim_private_key" rows="6" autocomplete="off" placeholder="Leer lassen = unverändert"></textarea><small><?php echo esc_html($dkimSource); ?></small></label><label class="astmail-switch"><input type="checkbox" name="clear_dkim_key" value="1"><span>Gespeicherten DKIM-Key löschen</span></label></div></div>
                <div class="astmail-actions"><button class="button button-primary">Konfiguration verschlüsselt speichern</button><span>Step-Up authentication required.</span></div>
            </form>
        </section>
        <?php
    }

    private static function security(SmtpConfig $config): void
    {
        ?>
        <div class="astmail-grid security-grid">
            <section class="astmail-panel"><span class="astmail-label">AT REST</span><strong>ASTRAEA AEAD</strong><p>Complete SMTP profile, including credentials and optional DKIM key, is encrypted under <code>MAIL_TRANSPORT</code> domain separation.</p></section>
            <section class="astmail-panel"><span class="astmail-label">IN TRANSIT</span><strong><?php echo esc_html(strtoupper($config->encryption)); ?></strong><p>TLS peer + hostname verification required. Self-signed certificates are rejected.</p></section>
            <section class="astmail-panel"><span class="astmail-label">DOWNGRADE</span><strong>BLOCKED</strong><p><code>SMTPAutoTLS</code> is disabled. Astraea uses only the explicitly configured secure mode.</p></section>
            <section class="astmail-panel"><span class="astmail-label">SECRETS</span><strong>OPAQUE</strong><p>Passwords, DKIM private keys, recipients and message bodies are never written to the Astraea delivery journal.</p></section>
        </div>
        <section class="astmail-panel"><h2>External Secret Mode</h2><p>For higher-assurance deployments, SMTP and OAuth secrets can be supplied outside the database. External values override encrypted stored credentials without being exposed to the browser.</p><div class="astmail-facts"><div><span>Username source</span><b><?php echo Settings::externalUsername() !== '' ? 'EXTERNAL' : 'ENCRYPTED STORE'; ?></b></div><div><span>Password source</span><b><?php echo Settings::externalPassword() !== '' ? 'EXTERNAL' : 'ENCRYPTED STORE'; ?></b></div><div><span>OAuth access token</span><b><?php echo Settings::externalOAuthAccessToken() !== '' ? 'EXTERNAL' : 'REFRESH FLOW / OFF'; ?></b></div><div><span>OAuth client secret</span><b><?php echo Settings::externalOAuthClientSecret() !== '' ? 'EXTERNAL' : 'ENCRYPTED STORE / EMPTY'; ?></b></div><div><span>OAuth refresh token</span><b><?php echo Settings::externalOAuthRefreshToken() !== '' ? 'EXTERNAL' : 'ENCRYPTED STORE / EMPTY'; ?></b></div><div><span>DKIM key source</span><b><?php echo Settings::externalDkimPrivateKey() !== '' ? 'EXTERNAL' : 'ENCRYPTED STORE'; ?></b></div><div><span>Private target authorization</span><b><?php echo $config->allowPrivateTarget ? 'EXPLICITLY ENABLED' : 'DENIED'; ?></b></div></div></section>
        <?php
    }

    private static function diagnostics(SmtpConfig $config, array $probe): void
    {
        ?>
        <div class="astmail-stack">
            <section class="astmail-panel"><div class="astmail-panel-head"><div><h2>SMTP handshake & authentication probe</h2><p>Connects using the stored profile, requires the configured TLS mode, validates the peer certificate and authenticates without sending a message.</p></div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('astraea_mail_probe'); ?><input type="hidden" name="action" value="astraea_mail_probe"><button class="button button-primary" <?php disabled($config->host === ''); ?>>Probe ausführen</button></form></div><?php if ($probe): ?><div class="astmail-facts"><div><span>Result</span><b><?php echo !empty($probe['success']) ? 'PASS' : 'FAIL'; ?></b></div><div><span>Checked</span><b><?php echo esc_html((string)($probe['datetime'] ?? '—')); ?></b></div><div><span>TLS Protocol</span><b><?php echo esc_html((string)($probe['tls_protocol'] ?? '—')); ?></b></div><div><span>Cipher</span><b><?php echo esc_html((string)($probe['tls_cipher'] ?? '—')); ?></b></div><div><span>Certificate</span><b><?php echo esc_html((string)($probe['certificate_subject'] ?? '—')); ?></b></div><div><span>Expires</span><b><?php echo esc_html((string)($probe['certificate_expires'] ?? '—')); ?></b></div><div><span>AUTH</span><b><?php echo esc_html(implode(', ', is_array($probe['auth_mechanisms'] ?? null) ? $probe['auth_mechanisms'] : [])); ?></b></div></div><?php if (!empty($probe['warnings']) && is_array($probe['warnings'])): ?><div class="astmail-warning-list"><?php foreach ($probe['warnings'] as $warning): ?><p><?php echo esc_html((string)$warning); ?></p><?php endforeach; ?></div><?php endif; ?><?php else: ?><div class="astmail-empty">Noch kein SMTP-Probe durchgeführt.</div><?php endif; ?></section>
            <section class="astmail-panel"><div class="astmail-panel-head"><div><h2>Test delivery</h2><p>Sends one real message through <code>wp_mail()</code> and therefore exercises the exact Astraea transport path used by WordPress and plugins.</p></div></div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="astmail-inline-form"><?php wp_nonce_field(Settings::TEST_NONCE); ?><input type="hidden" name="action" value="astraea_mail_test"><input type="email" name="recipient" value="<?php echo esc_attr((string)get_option('admin_email', '')); ?>" required><button class="button button-primary" <?php disabled(!$config->enabled); ?>>Testmail senden</button></form><small>Rate limited and Step-Up protected. Recipient address is not persisted.</small></section>
            <section class="astmail-panel"><div class="astmail-panel-head"><div><h2>Circuit breaker</h2><p>Five transport failures in the failure window trigger a temporary cooldown instead of repeatedly blocking PHP requests on a dead SMTP server.</p></div><div><span class="astmail-state <?php echo DeliveryJournal::circuitBreakerOpen() ? 'warn' : 'ok'; ?>"><?php echo DeliveryJournal::circuitBreakerOpen() ? 'COOLDOWN' : 'READY'; ?></span><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field(Settings::RESET_BREAKER_NONCE); ?><input type="hidden" name="action" value="astraea_mail_reset_breaker"><button class="button">Reset</button></form></div></div></section>
        </div>
        <?php
    }

    private static function journal(array $journal): void
    {
        ?>
        <section class="astmail-panel"><div class="astmail-panel-head"><div><h2>Encrypted Delivery Journal</h2><p>Metadata only. No recipient addresses, credentials, message subject/body or attachment paths are persisted.</p></div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field(Settings::CLEAR_NONCE); ?><input type="hidden" name="action" value="astraea_mail_clear_journal"><button class="button">Journal leeren</button></form></div><?php if (!$journal): ?><div class="astmail-empty">Noch keine SMTP-Delivery-Events.</div><?php else: ?><div class="astmail-journal"><div class="head"><span>Status</span><span>Zeit</span><span>Provider</span><span>TLS</span><span>Empfänger #</span><span>Code</span></div><?php foreach ($journal as $entry): ?><div class="row"><span><i class="dot <?php echo esc_attr((string)($entry['status'] ?? 'failed')); ?>"></i><?php echo esc_html(strtoupper((string)($entry['status'] ?? 'unknown'))); ?></span><span><?php echo esc_html((string)($entry['datetime'] ?? '')); ?></span><span><?php echo esc_html((string)($entry['provider_label'] ?? $entry['provider'] ?? '')); ?></span><span><?php echo esc_html(strtoupper((string)($entry['encryption'] ?? ''))); ?></span><span><?php echo esc_html((string)($entry['recipients'] ?? 0)); ?></span><span><code><?php echo esc_html((string)($entry['code'] ?? '')); ?></code></span></div><?php endforeach; ?></div><?php endif; ?></section>
        <?php
    }

    public static function save(): never
    {
        self::guard(Settings::SAVE_NONCE, 'mail:configuration_change');
        $existing = EncryptedConfigStore::load();
        $data = wp_unslash($_POST);
        $submittedPassword = isset($data['password']) && is_string($data['password']) ? $data['password'] : '';
        $data['password'] = !empty($_POST['clear_password']) ? '' : ($submittedPassword !== '' ? $submittedPassword : $existing->password);
        $submittedOAuthClientSecret = isset($data['oauth_client_secret']) && is_string($data['oauth_client_secret']) ? $data['oauth_client_secret'] : '';
        $data['oauth_client_secret'] = !empty($_POST['clear_oauth_client_secret']) ? '' : ($submittedOAuthClientSecret !== '' ? $submittedOAuthClientSecret : $existing->oauthClientSecret);
        $submittedOAuthRefreshToken = isset($data['oauth_refresh_token']) && is_string($data['oauth_refresh_token']) ? $data['oauth_refresh_token'] : '';
        $data['oauth_refresh_token'] = !empty($_POST['clear_oauth_refresh_token']) ? '' : ($submittedOAuthRefreshToken !== '' ? $submittedOAuthRefreshToken : $existing->oauthRefreshToken);
        $submittedDkim = isset($data['dkim_private_key']) && is_string($data['dkim_private_key']) ? $data['dkim_private_key'] : '';
        $data['dkim_private_key'] = !empty($_POST['clear_dkim_key']) ? '' : ($submittedDkim !== '' ? $submittedDkim : $existing->dkimPrivateKey);

        try {
            $config = SmtpConfig::fromArray($data);
            $config->assertOperational(true);
            if ($config->enabled) {
                EndpointPolicy::assertTransportAllowed($config->host, $config->allowPrivateTarget, $config->encryption);
            }
            EncryptedConfigStore::save($config);
            OAuthTokenService::clearCache();
            TransportManager::resetConfigCache();
            SecurityEventManager::recordEvent(SecurityEventManager::SEVERITY_INFO, 'Mail', 'configuration_changed', 'Astraea Mail transport configuration was changed by an administrator.', ['user_id' => get_current_user_id()]);
            self::redirect('configuration', 'saved');
        } catch (ValidationException $e) {
            self::redirect('configuration', 'invalid');
        } catch (SecurityException $e) {
            error_log('[ASTRAEA][MAIL][SEC] ' . $e->getMessage());
            SecurityEventManager::recordEvent(SecurityEventManager::SEVERITY_WARNING, 'Mail', 'configuration_rejected', 'Astraea Mail rejected a transport configuration by security policy.', ['user_id' => get_current_user_id()]);
            self::redirect('configuration', 'rejected');
        } catch (StorageException $e) {
            error_log('[ASTRAEA][MAIL][STORAGE] ' . $e->getMessage());
            self::redirect('configuration', 'storage');
        } catch (\Throwable $e) {
            error_log('[ASTRAEA][MAIL][FATAL] ' . get_class($e));
            self::redirect('configuration', 'failed');
        }
    }

    public static function probe(): never
    {
        self::guard('astraea_mail_probe', 'mail:diagnostics');
        try {
            $probeConfig = EncryptedConfigStore::load();
            if (EncryptedConfigStore::hasLoadFailure()) {
                throw new SecurityException('Encrypted SMTP configuration authentication failed.');
            }
            $probeConfig->assertOperational(true);
            $result = SmtpProbe::run($probeConfig);
            SecurityEventManager::recordEvent(SecurityEventManager::SEVERITY_INFO, 'Mail', 'smtp_probe_passed', 'SMTP transport diagnostics completed successfully.', []);
            self::redirect('diagnostics', 'probe_ok');
        } catch (SecurityException $e) {
            error_log('[ASTRAEA][MAIL][SEC] SMTP probe rejected: ' . $e->getMessage());
            SecurityEventManager::recordEvent(SecurityEventManager::SEVERITY_WARNING, 'Mail', 'smtp_probe_rejected', 'SMTP diagnostics failed a security control.', []);
            self::redirect('diagnostics', 'probe_security');
        } catch (\Throwable $e) {
            error_log('[ASTRAEA][MAIL] SMTP probe failed: ' . get_class($e));
            SecurityEventManager::recordEvent(SecurityEventManager::SEVERITY_WARNING, 'Mail', 'smtp_probe_failed', 'SMTP diagnostics could not establish the configured transport.', []);
            self::redirect('diagnostics', 'probe_failed');
        }
    }

    public static function sendTest(): never
    {
        self::guard(Settings::TEST_NONCE, 'mail:test_delivery');
        $recipient = isset($_POST['recipient']) && is_string($_POST['recipient']) ? sanitize_email((string)wp_unslash($_POST['recipient'])) : '';
        if ($recipient === '' || !is_email($recipient)) {
            self::redirect('diagnostics', 'recipient_invalid');
        }
        try {
            self::assertTestRateLimit();
        } catch (SecurityException $e) {
            SecurityEventManager::recordOnce(SecurityEventManager::SEVERITY_WARNING, 'Mail', 'test_rate_limited', 'SMTP test delivery was rate limited.', ['user_id' => get_current_user_id()], 60);
            self::redirect('diagnostics', 'test_rate');
        }
        $config = EncryptedConfigStore::load();
        if (!$config->enabled) {
            self::redirect('diagnostics', 'disabled');
        }
        $site = wp_specialchars_decode((string)get_bloginfo('name'), ENT_QUOTES);
        $ok = wp_mail($recipient, 'Astraea Mail Gateway · Transport Test', "AstraeaOS WP SMTP transport test for {$site}.\n\nIf you received this message, the configured SMTP path accepted a real wp_mail() delivery.");
        self::recordTestAttempt();
        self::redirect('diagnostics', $ok ? 'test_ok' : 'test_failed');
    }

    public static function clearJournal(): never
    {
        self::guard(Settings::CLEAR_NONCE, 'mail:journal_clear');
        DeliveryJournal::clear();
        self::redirect('journal', 'journal_cleared');
    }

    public static function resetBreaker(): never
    {
        self::guard(Settings::RESET_BREAKER_NONCE, 'mail:breaker_reset');
        DeliveryJournal::resetBreaker();
        SecurityEventManager::recordEvent(SecurityEventManager::SEVERITY_INFO, 'Mail', 'circuit_breaker_reset', 'SMTP circuit breaker was reset by an administrator.', ['user_id' => get_current_user_id()]);
        self::redirect('diagnostics', 'breaker_reset');
    }

    private static function guard(string $nonce, string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Forbidden.', 'astraeaos'), '', ['response' => 403]);
        }
        check_admin_referer($nonce);
        $userId = get_current_user_id();
        if (!empty($_POST['astraea_stepup_password']) && is_string($_POST['astraea_stepup_password'])) {
            try {
                if (StepUpAuthService::verifyStepUpChallenge($userId, (string)wp_unslash($_POST['astraea_stepup_password']))) {
                    return;
                }
            } catch (\Throwable) {}
        }
        try {
            StepUpAuthService::guardSensitiveAction($userId, $action);
        } catch (SecurityException $e) {
            SecurityEventManager::recordOnce(SecurityEventManager::SEVERITY_WARNING, 'Authorization', 'mail_step_up_required', 'Astraea Mail blocked a privileged operation pending current-session re-authentication.', ['action' => $action], 30);
            $verifyUrl = StepUpAuthService::getVerificationUrl(admin_url('admin.php?page=astraea-mail&tab=settings'));
            $title = esc_html__('Step-Up Re-Authentication Required', 'astraeaos');
            $buttonText = esc_html__('Re-authenticate in Security → Sessions now', 'astraeaos');
            $msg = sprintf(
                esc_html__('Current-session re-authentication is required in Security → Sessions. %s', 'astraeaos'),
                '<br><br><a href="' . esc_url($verifyUrl) . '" class="button button-primary" style="display:inline-block;padding:8px 16px;text-decoration:none;border-radius:6px;">' . $buttonText . '</a>'
            );
            wp_die($msg, $title, ['response' => 403, 'back_link' => true]);
        }
    }

    private static function assertTestRateLimit(): void
    {
        $key = self::testRateKey();
        if ((int)get_transient($key) >= Settings::TEST_RATE_LIMIT) {
            throw new SecurityException('SMTP test delivery rate limit exceeded.');
        }
    }

    private static function recordTestAttempt(): void
    {
        $key = self::testRateKey();
        $count = (int)get_transient($key);
        set_transient($key, $count + 1, Settings::TEST_RATE_WINDOW);
    }

    private static function testRateKey(): string
    {
        return 'astraea_mail_test_' . substr(hash('sha256', get_current_user_id() . '|' . StepUpAuthService::currentSessionFingerprint()), 0, 32);
    }

    private static function redirect(string $tab, string $status): never
    {
        wp_safe_redirect(add_query_arg(['page' => 'astraea-mail', 'tab' => $tab, 'mail_status' => $status], admin_url('admin.php')));
        exit;
    }

    private static function notice(string $status): void
    {
        if ($status === '') return;
        $messages = [
            'saved' => ['ok', 'SMTP-Konfiguration wurde verschlüsselt gespeichert.'],
            'invalid' => ['bad', 'Konfiguration enthält ungültige Werte.'],
            'rejected' => ['bad', 'Konfiguration wurde von der Astraea Security Policy abgelehnt.'],
            'storage' => ['bad', 'Verschlüsselte Konfiguration konnte nicht gespeichert werden.'],
            'failed' => ['bad', 'Konfigurationsvorgang ist fehlgeschlagen.'],
            'probe_ok' => ['ok', 'SMTP Handshake, TLS-Policy und Authentifizierung wurden erfolgreich geprüft.'],
            'probe_security' => ['bad', 'SMTP-Probe wurde von einer Security-Kontrolle abgelehnt.'],
            'probe_failed' => ['bad', 'SMTP-Probe konnte den konfigurierten Transport nicht herstellen.'],
            'test_ok' => ['ok', 'Testmail wurde vom SMTP-Transport akzeptiert.'],
            'test_failed' => ['bad', 'Testmail konnte nicht zugestellt werden.'],
            'test_rate' => ['bad', 'Zu viele Testmails in dieser Sitzung. Bitte später erneut versuchen.'],
            'recipient_invalid' => ['bad', 'Testempfänger ist ungültig.'],
            'disabled' => ['bad', 'SMTP Gateway ist deaktiviert.'],
            'journal_cleared' => ['ok', 'Delivery Journal wurde gelöscht.'],
            'breaker_reset' => ['ok', 'SMTP Circuit Breaker wurde zurückgesetzt.'],
        ];
        if (!isset($messages[$status])) return;
        [$class, $message] = $messages[$status];
        echo '<div class="astmail-notice ' . esc_attr($class) . '">' . esc_html($message) . '</div>';
    }
}
