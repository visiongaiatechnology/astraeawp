<?php
// STATUS: DIAMANT VGT SUPREME

declare(strict_types=1);

namespace Astraea\Mail\Transport;

use Astraea\Mail\Security\EndpointPolicy;
use Astraea\Mail\Store\EncryptedConfigStore;
use Astraea\Mail\SmtpConfig;
use Astraea\Security\SecurityEventManager;
use PHPMailer\PHPMailer\PHPMailer;

final class TransportManager
{
    private static ?SmtpConfig $config = null;
    private static bool $preflightPassed = false;
    /** @var list<string> */
    private static array $pinnedAddresses = [];

    public static function init(): void
    {
        add_filter('pre_wp_mail', [self::class, 'preflight'], 0, 2);
        add_action('phpmailer_init', [self::class, 'configureMailer'], 1);
        add_filter('wp_mail_from', [self::class, 'filterFromEmail'], 100);
        add_filter('wp_mail_from_name', [self::class, 'filterFromName'], 100);
        add_action('wp_mail_succeeded', [self::class, 'mailSucceeded']);
        add_action('wp_mail_failed', [self::class, 'mailFailed']);
    }

    public static function isEnabled(): bool
    {
        return self::config()->enabled;
    }

    public static function config(): SmtpConfig
    {
        return self::$config ??= EncryptedConfigStore::load();
    }

    public static function resetConfigCache(): void
    {
        self::$config = null;
        self::$preflightPassed = false;
        self::$pinnedAddresses = [];
    }

    public static function preflight(mixed $return, array $atts): mixed
    {
        $config = self::config();
        if (EncryptedConfigStore::hasLoadFailure()) {
            SecurityEventManager::recordOnce(SecurityEventManager::SEVERITY_CRITICAL, 'Mail', 'config_decrypt_failed', 'Astraea Mail blocked delivery because the encrypted transport configuration could not be authenticated or decoded.', [], 60);
            return false;
        }
        if (!$config->enabled) {
            return $return;
        }
        if (DeliveryJournal::circuitBreakerOpen()) {
            DeliveryJournal::record('blocked', $config, self::recipientCount($atts['to'] ?? []), 'circuit_breaker', 'Delivery circuit breaker is cooling down after repeated SMTP failures.');
            SecurityEventManager::recordOnce(SecurityEventManager::SEVERITY_WARNING, 'Mail', 'circuit_breaker_open', 'Astraea Mail blocked delivery while the SMTP failure circuit breaker is active.', [], 60);
            return false;
        }
        try {
            $config->assertOperational(false);
            EndpointPolicy::assertTransportAllowed($config->host, $config->allowPrivateTarget, $config->encryption);
            self::$pinnedAddresses = EndpointPolicy::resolve($config->host);
            if (self::$pinnedAddresses === []) {
                throw new \RuntimeException('SMTP endpoint resolution produced no pinnable addresses.');
            }
            self::$preflightPassed = true;
            return $return;
        } catch (\Throwable $e) {
            self::$preflightPassed = false;
            self::$pinnedAddresses = [];
            DeliveryJournal::record('blocked', $config, self::recipientCount($atts['to'] ?? []), 'policy_rejected', 'SMTP endpoint policy rejected the configured transport.');
            SecurityEventManager::recordOnce(SecurityEventManager::SEVERITY_CRITICAL, 'Mail', 'transport_policy_rejected', 'Astraea Mail rejected the configured SMTP endpoint before delivery.', [], 60);
            return false;
        }
    }

    public static function configureMailer(PHPMailer $mailer): void
    {
        $config = self::config();
        if (!$config->enabled || !self::$preflightPassed) {
            return;
        }
        MailRuntime::ensureLoaded();
        $mailer->isSMTP();
        $strictSmtp = new StrictSMTP();
        $strictSmtp->pinEndpoint($config->host, self::$pinnedAddresses);
        $strictSmtp->do_debug = 0;
        $strictSmtp->Timelimit = max(10, $config->timeout * 2);
        $mailer->setSMTPInstance($strictSmtp);
        $mailer->Host = $config->host;
        $mailer->Port = $config->port;
        $mailer->SMTPAuth = $config->auth;
        $mailer->Username = $config->effectiveUsername();
        if ($config->auth && $config->authType === 'xoauth2') {
            $mailer->Password = '';
            $mailer->AuthType = 'XOAUTH2';
            $mailer->setOAuth(new OAuthProvider($config));
        } else {
            $mailer->Password = $config->effectivePassword();
            $mailer->AuthType = $config->authType === 'auto' ? '' : strtoupper($config->authType);
        }
        $mailer->SMTPAutoTLS = false;
        $mailer->SMTPOptions = TlsPolicy::streamOptions($config->host);
        $mailer->Timeout = $config->timeout;
        $mailer->SMTPKeepAlive = false;
        $mailer->SMTPDebug = 0;
        $mailer->Debugoutput = static function (): void {};
        $mailer->XMailer = 'AstraeaOS WP/' . (defined('ASTRAEA_VERSION') ? ASTRAEA_VERSION : 'unknown');

        if ($config->encryption === 'starttls') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($config->encryption === 'smtps') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mailer->SMTPSecure = '';
        }

        if ($config->returnPath && $config->fromEmail !== '') {
            $mailer->Sender = $config->fromEmail;
        }

        if ($config->dkimEnabled) {
            $mailer->DKIM_domain = $config->dkimDomain;
            $mailer->DKIM_selector = $config->dkimSelector;
            $mailer->DKIM_identity = $config->dkimIdentity !== '' ? $config->dkimIdentity : $config->fromEmail;
            $mailer->DKIM_private_string = $config->effectiveDkimPrivateKey();
            $mailer->DKIM_copyHeaderFields = true;
        }
    }

    public static function filterFromEmail(string $email): string
    {
        $config = self::config();
        return $config->enabled && $config->forceFromEmail && $config->fromEmail !== '' ? $config->fromEmail : $email;
    }

    public static function filterFromName(string $name): string
    {
        $config = self::config();
        return $config->enabled && $config->forceFromName && $config->fromName !== '' ? $config->fromName : $name;
    }

    /** @param array<string,mixed> $mailData */
    public static function mailSucceeded(array $mailData): void
    {
        $config = self::config();
        if (!$config->enabled) {
            return;
        }
        DeliveryJournal::recordSuccess();
        DeliveryJournal::record('sent', $config, self::recipientCount($mailData['to'] ?? []), 'accepted', 'Message accepted by the configured SMTP transport.');
    }

    public static function mailFailed(\WP_Error $error): void
    {
        $config = self::config();
        if (!$config->enabled) {
            return;
        }
        DeliveryJournal::recordFailure();
        $code = is_string($error->get_error_code()) ? $error->get_error_code() : 'smtp_failed';
        DeliveryJournal::record('failed', $config, 0, $code, 'SMTP delivery failed. Server details were intentionally not persisted.');
        SecurityEventManager::recordOnce(SecurityEventManager::SEVERITY_WARNING, 'Mail', 'delivery_failed', 'Astraea Mail recorded an SMTP delivery failure without persisting message contents or credentials.', ['error_code' => $code], 30);
    }

    private static function recipientCount(mixed $to): int
    {
        if (is_array($to)) {
            return count($to);
        }
        if (is_string($to) && $to !== '') {
            return count(array_filter(array_map('trim', explode(',', $to)), static fn(string $v): bool => $v !== ''));
        }
        return 0;
    }
}
