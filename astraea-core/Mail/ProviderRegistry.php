<?php
// STATUS: DIAMANT VGT SUPREME

declare(strict_types=1);

namespace Astraea\Mail;

final class ProviderRegistry
{
    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        return [
            'custom' => [
                'label' => 'Custom SMTP', 'host' => '', 'port' => 587, 'encryption' => 'starttls', 'auth_type' => 'auto', 'oauth_token_url' => '', 'oauth_scope' => '',
                'note' => 'Beliebiger RFC-kompatibler SMTP Submission Server.',
            ],
            'gmail' => [
                'label' => 'Google Gmail / Workspace', 'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'starttls', 'auth_type' => 'auto', 'oauth_token_url' => 'https://oauth2.googleapis.com/token', 'oauth_scope' => 'https://mail.google.com/',
                'note' => 'App-Passwort oder vom Konto akzeptierte SMTP-Anmeldedaten verwenden.',
            ],
            'microsoft365' => [
                'label' => 'Microsoft 365', 'host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'starttls', 'auth_type' => 'xoauth2', 'oauth_token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token', 'oauth_scope' => 'https://outlook.office.com/SMTP.Send offline_access',
                'note' => 'Modern Auth / XOAUTH2. SMTP AUTH muss im Tenant erlaubt sein; Refresh-Token oder externes Access-Token erforderlich.',
            ],
            'outlook' => [
                'label' => 'Outlook.com / Hotmail', 'host' => 'smtp-mail.outlook.com', 'port' => 587, 'encryption' => 'starttls', 'auth_type' => 'xoauth2', 'oauth_token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token', 'oauth_scope' => 'https://outlook.office.com/SMTP.Send offline_access',
                'note' => 'Modern Auth / XOAUTH2 empfohlen; Refresh-Token oder externes Access-Token verwenden.',
            ],
            'yahoo' => [
                'label' => 'Yahoo Mail', 'host' => 'smtp.mail.yahoo.com', 'port' => 465, 'encryption' => 'smtps', 'auth_type' => 'auto', 'oauth_token_url' => '', 'oauth_scope' => '',
                'note' => 'Üblicherweise App-Passwort verwenden.',
            ],
            'icloud' => [
                'label' => 'Apple iCloud Mail', 'host' => 'smtp.mail.me.com', 'port' => 587, 'encryption' => 'starttls', 'auth_type' => 'auto', 'oauth_token_url' => '', 'oauth_scope' => '',
                'note' => 'App-spezifisches Passwort verwenden.',
            ],
            'zoho' => [
                'label' => 'Zoho Mail', 'host' => 'smtp.zoho.com', 'port' => 587, 'encryption' => 'starttls', 'auth_type' => 'auto', 'oauth_token_url' => '', 'oauth_scope' => '',
                'note' => 'Regionale Zoho-Hosts können bei Bedarf überschrieben werden.',
            ],
            'fastmail' => [
                'label' => 'Fastmail', 'host' => 'smtp.fastmail.com', 'port' => 465, 'encryption' => 'smtps', 'auth_type' => 'auto', 'oauth_token_url' => '', 'oauth_scope' => '',
                'note' => 'App-Passwort empfohlen.',
            ],
            'gmx' => [
                'label' => 'GMX', 'host' => 'mail.gmx.net', 'port' => 587, 'encryption' => 'starttls', 'auth_type' => 'auto', 'oauth_token_url' => '', 'oauth_scope' => '',
                'note' => 'SMTP-Zugriff muss im GMX-Konto aktiviert sein.',
            ],
            'webde' => [
                'label' => 'WEB.DE', 'host' => 'smtp.web.de', 'port' => 587, 'encryption' => 'starttls', 'auth_type' => 'auto', 'oauth_token_url' => '', 'oauth_scope' => '',
                'note' => 'SMTP-Zugriff muss im WEB.DE-Konto aktiviert sein.',
            ],
            'mailboxorg' => [
                'label' => 'mailbox.org', 'host' => 'smtp.mailbox.org', 'port' => 465, 'encryption' => 'smtps', 'auth_type' => 'auto', 'oauth_token_url' => '', 'oauth_scope' => '',
                'note' => 'Alternativ STARTTLS über Port 587 möglich.',
            ],
            'sendgrid' => [
                'label' => 'Twilio SendGrid', 'host' => 'smtp.sendgrid.net', 'port' => 587, 'encryption' => 'starttls', 'auth_type' => 'auto', 'oauth_token_url' => '', 'oauth_scope' => '',
                'note' => 'Bei API-Key-SMTP ist der Benutzername üblicherweise „apikey“.',
            ],
            'mailgun' => [
                'label' => 'Mailgun', 'host' => 'smtp.mailgun.org', 'port' => 587, 'encryption' => 'starttls', 'auth_type' => 'auto', 'oauth_token_url' => '', 'oauth_scope' => '',
                'note' => 'SMTP-Credentials aus der Mailgun-Domain verwenden.',
            ],
            'brevo' => [
                'label' => 'Brevo', 'host' => 'smtp-relay.brevo.com', 'port' => 587, 'encryption' => 'starttls', 'auth_type' => 'auto', 'oauth_token_url' => '', 'oauth_scope' => '',
                'note' => 'SMTP-Login und SMTP-Key aus Brevo verwenden.',
            ],
            'mailjet' => [
                'label' => 'Mailjet', 'host' => 'in-v3.mailjet.com', 'port' => 587, 'encryption' => 'starttls', 'auth_type' => 'auto', 'oauth_token_url' => '', 'oauth_scope' => '',
                'note' => 'Mailjet SMTP API Key / Secret als Credentials verwenden.',
            ],
            'postmark' => [
                'label' => 'Postmark', 'host' => 'smtp.postmarkapp.com', 'port' => 587, 'encryption' => 'starttls', 'auth_type' => 'auto', 'oauth_token_url' => '', 'oauth_scope' => '',
                'note' => 'Server Token als SMTP-Credential gemäß Postmark-Konfiguration verwenden.',
            ],
            'amazon_ses' => [
                'label' => 'Amazon SES', 'host' => 'email-smtp.us-east-1.amazonaws.com', 'port' => 587, 'encryption' => 'starttls', 'auth_type' => 'auto', 'oauth_token_url' => '', 'oauth_scope' => '',
                'note' => 'Region im Host anpassen und SES SMTP-Credentials verwenden.',
            ],
            'smtp2go' => [
                'label' => 'SMTP2GO', 'host' => 'mail.smtp2go.com', 'port' => 587, 'encryption' => 'starttls', 'auth_type' => 'auto', 'oauth_token_url' => '', 'oauth_scope' => '',
                'note' => 'SMTP2GO SMTP-Benutzer und Passwort verwenden.',
            ],
        ];
    }

    public static function exists(string $provider): bool
    {
        return array_key_exists($provider, self::all());
    }

    /** @return array<string,mixed> */
    public static function get(string $provider): array
    {
        $all = self::all();
        return $all[$provider] ?? $all['custom'];
    }
}
