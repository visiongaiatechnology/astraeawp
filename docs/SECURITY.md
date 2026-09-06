# AstraeaOS WP — Security Architecture & Hardening Defaults

**Distribution:** AstraeaOS WP `0.6.0-alpha`  
**Security Posture:** Security-by-Default, Zero-Trust Ingress, Defense-in-Depth  

---

## 1. Core Hardening Principles

AstraeaOS WP departs from legacy WordPress by enforcing strict security invariants out of the box without requiring dozens of disparate third-party plugins.

1. **No Security through Obscurity:** Protection relies on established cryptographic primitives, explicit invariants, strict validation, and layered authorization.
2. **Fail-Closed Strategy:** Any authentication failure, cryptographic tampering, or unauthenticated request to protected endpoints results in immediate rejection (HTTP 403 / 503).
3. **CSPRNG Exclusivity:** All security tokens, salts, nonces, and session identifiers are generated using operating system entropy via `random_bytes()`. Legacy generators (`rand()`, `mt_rand()`) are banned for security logic.

---

## 2. Cookie & Session Security

- **`Secure` Flag:** Enforced unconditionally whenever requests are served over HTTPS.
- **`HttpOnly` Flag:** Mandated on all authentication cookies (`wordpress_logged_in_*`, `wordpress_sec_*`) to prevent token exfiltration via Cross-Site Scripting (XSS).
- **`SameSite=Lax`:** Applied by default, offering strong protection against Cross-Site Request Forgery (CSRF).
- **Session Tokens:** Bound to high-entropy random identifiers with shortened lifetimes (12-hour nonce expiration vs. upstream 24-hour default).

---

## 3. Ingress File Upload Guard (`FileGuard`)

The media library is a historic attack vector for PHP shell uploads. `Astraea\Security\FileGuard` intercepts `wp_handle_upload_prefilter`:

1. **Server-Side MIME Inspection:** Uses native PHP `fileinfo` magic-byte scanning. Browser-supplied `Content-Type` headers are ignored.
2. **Double-Extension Blocking:** Filenames containing executable extensions prior to a valid extension (e.g. `exploit.php.jpg`, `payload.phtml.png`) are rejected immediately.
3. **Traversal Prevention:** Strips and blocks `../`, null-byte injections (`%00`), and leading slashes.
4. **Forbidden Executable Whitelist:** Outrights rejects `.php`, `.phtml`, `.phar`, `.exe`, `.bat`, `.cmd`, `.sh`, `.cgi`, `.pl`, `.py`, `.htaccess`, and `.user.ini`.
5. **SVG Sanitization:** Inspects XML payloads to block inline `<script>`, `onload` handlers, and XML External Entity (`XXE`) definitions.

---

## 4. HTTP Security Headers

AstraeaOS automatically injects modern defense headers:
- `X-Content-Type-Options: nosniff` — Prevents MIME-type confusion attacks.
- `X-Frame-Options: SAMEORIGIN` — Mitigates clickjacking.
- `Referrer-Policy: strict-origin-when-cross-origin` — Protects internal URI paths from leaking to external referrers.
- `Permissions-Policy: camera=(), microphone=(), geolocation=()` — Restricts unneeded browser APIs.
- `Strict-Transport-Security: max-age=31536000` — Enabled on HTTPS by the Astraea fallback policy. `includeSubDomains` and `preload` are explicit opt-in controls and are never asserted by default.
- Strips `X-Powered-By` to prevent server software disclosure.

---

## 5. Structured Audit Logging & Secret Redaction

`Astraea\Security\Logger` emits standardized JSON logs with automatic sanitization of sensitive values:
- Passwords (`user_pass`, `password`)
- API Tokens (`token`, `api_key`)
- Master Keys (`master_key`, `secret`)
- Authorization headers (`Authorization: Bearer ...`)

Values matching sensitive keys are automatically masked as `[REDACTED]` before writing to disk or stderr.

## 6. Astraea Mail Gateway

The kernel-native mail layer applies the same fail-closed and encrypted-at-rest policy used by other Astraea security domains.

- SMTP configuration is persisted as an authenticated AEAD envelope under the dedicated `MAIL_TRANSPORT` key context.
- SMTP passwords, OAuth refresh/client secrets, cached OAuth access tokens, DKIM private keys, diagnostics and delivery telemetry are never stored as plaintext WordPress options.
- STARTTLS and SMTPS verify the peer certificate and peer name; self-signed peers are rejected.
- Astraea disables opportunistic TLS switching and constrains STARTTLS to TLS 1.2/1.3.
- Public plaintext SMTP is rejected. `none` transport is permitted only for explicitly authorized private/loopback relays.
- SMTP endpoints are subject to DNS/IP policy that rejects cloud metadata destinations and private targets unless the private-relay policy explicitly permits the latter.
- OAuth token endpoints are HTTPS-only, reject redirects and unsafe/private destinations, and use bounded response parsing.
- Microsoft/Outlook Modern Auth is provided through native XOAUTH2 and the WordPress HTTP API; no third-party OAuth SDK is loaded.
- Tampered/unreadable encrypted mail configuration blocks mail transport and does not silently fall back to PHP `mail()`.
- Sensitive mail configuration and diagnostic actions require Astraea session-bound Step-Up authentication.
- Delivery telemetry excludes recipients, subject, message body, attachment paths, passwords and OAuth tokens.

