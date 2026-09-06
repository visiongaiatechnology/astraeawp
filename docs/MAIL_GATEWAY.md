# Astraea Mail Gateway

**Distribution:** AstraeaOS WP 0.6.0-alpha  
**Module:** Astraea Mail Gateway 1.0.0-alpha  
**Architecture:** kernel-native, zero external runtime dependencies

## Purpose

Astraea Mail Gateway replaces the usual SMTP plugin layer with a first-party transport configuration inside the AstraeaOS kernel. WordPress' already bundled PHPMailer/SMTP implementation is used as the protocol parser and MIME transport engine; Astraea supplies policy, configuration, encryption, diagnostics, UI and failure handling. No Composer package, npm package, CDN or external runtime library is fetched.

## Security model

- Entire SMTP profile is persisted as one authenticated Astraea AEAD envelope using the dedicated `MAIL_TRANSPORT` key domain.
- SMTP password, optional DKIM private key, diagnostic metadata and delivery journal are never stored in plaintext options.
- OAuth client secret, refresh token and cached access token are encrypted through Astraea storage; external secrets can override DB values without being exposed to the browser.
- OAuth token refresh uses the native WordPress HTTP API with HTTPS-only endpoint validation, no redirects, unsafe/private-target rejection and bounded response parsing.
- Environment/constant secrets `ASTRAEA_SMTP_USERNAME`, `ASTRAEA_SMTP_PASSWORD`, `ASTRAEA_SMTP_OAUTH_ACCESS_TOKEN`, `ASTRAEA_SMTP_OAUTH_CLIENT_SECRET`, `ASTRAEA_SMTP_OAUTH_REFRESH_TOKEN` and `ASTRAEA_DKIM_PRIVATE_KEY` may override encrypted database values.
- STARTTLS and SMTPS require certificate and peer-name validation; self-signed certificates are rejected.
- STARTTLS is negotiated by `StrictSMTP`, restricted to TLS 1.2/1.3 methods.
- `SMTPAutoTLS` is disabled: transport security never silently changes from the configured policy.
- Plaintext SMTP is permitted only for explicitly authorized endpoints that resolve exclusively to private/loopback addresses.
- Cloud metadata endpoints are blocked even when private-relay authorization is enabled.
- DNS/private target validation runs before delivery and diagnostics.
- Corrupt/unreadable encrypted SMTP configuration fails closed and prevents fallback through default PHP mail.
- Credential changes, diagnostics, test delivery, journal deletion and breaker reset require session-bound Astraea Step-Up authentication.
- Delivery telemetry persists no recipients, subject, body, credentials or attachment paths.

## Providers

The UI includes editable presets for common SMTP providers including Gmail / Google Workspace, Microsoft 365, Outlook.com, Yahoo, iCloud, Zoho, Fastmail, GMX, WEB.DE, mailbox.org, SendGrid, Mailgun, Brevo, Mailjet, Postmark, Amazon SES and SMTP2GO. `Custom SMTP` accepts any compatible server within the endpoint policy.

Provider presets are convenience values, not a replacement for provider account policy. Microsoft 365 and Outlook.com presets use native XOAUTH2/Modern Auth. Other services may require app passwords, dedicated SMTP credentials, SMTP AUTH enablement, provider-specific OAuth consent, or account configuration.

## XOAUTH2 / Modern Auth

Astraea implements the SMTP XOAUTH2 token provider directly against WordPress' bundled PHPMailer interfaces. It does not load an OAuth SDK.

The token path supports either an externally supplied `ASTRAEA_SMTP_OAUTH_ACCESS_TOKEN` or a refresh-token flow using the configured HTTPS token endpoint, Client ID, optional Client Secret and Refresh Token. Access-token cache records are AEAD encrypted and scoped to the effective OAuth configuration. Secret-bearing remote error bodies are not written to logs or journals.

## Failure behavior

Five transport failures inside the configured failure window open a temporary circuit breaker. During cooldown, additional messages are fail-closed instead of repeatedly blocking application requests on a dead mail server. Successful delivery or an explicit Step-Up-protected reset clears the breaker.

## Diagnostics

The diagnostic probe connects to the configured endpoint, performs EHLO, enforces the configured TLS mode, authenticates when enabled, and stores an encrypted summary containing protocol/cipher, certificate subject/issuer/expiry and advertised authentication mechanisms. It does not send a message.

A separate test delivery uses real `wp_mail()` and therefore exercises the same Astraea transport path used by WordPress and plugins. Test delivery is session rate-limited and Step-Up protected.

## DKIM

Optional DKIM signing uses the PHPMailer implementation already shipped with WordPress. The private key is stored inside the Astraea encrypted mail profile, or supplied externally through `ASTRAEA_DKIM_PRIVATE_KEY`. Astraea does not generate or publish DNS records automatically.

## Known scope

Astraea Mail Gateway controls mail sent through the normal WordPress `wp_mail()`/PHPMailer path. Code that bypasses WordPress and directly calls PHP `mail()`, opens its own SMTP socket, or replaces the pluggable `wp_mail()` function with an incompatible implementation is outside this transport path and should be treated as a separate integration during compatibility testing.
