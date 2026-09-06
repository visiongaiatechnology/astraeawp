# AstraeaOS WP Secure Genesis

**Distribution:** AstraeaOS WP `0.6.0-alpha`  
**Status:** alpha engineering implementation; not an external security certification.

## Purpose

Secure Genesis turns installation into a resumable security transaction. WordPress core installation is a milestone, not the security finish line. Astraea reaches `READY` only after its installation plan has been persisted, ThroneGuard provisioned, GeDefense policy applied, and install-time verification completed. A first normal boot then performs a separate runtime verification of the configured security modules.

## State model

```text
CORE_INSTALLED
    -> SECURITY_APPLYING
    -> SECURITY_VERIFYING
    -> READY

Any post-core failure -> SECURITY_FAILED (resumable)
```

A high-entropy resume credential is stored only as a SHA-256 verifier in the database and as an HttpOnly, SameSite=Strict browser cookie. The ThroneGuard recovery key is never placed in the resumable install plan.

## Preflight

The installer reports measured state for PHP, native Sodium, OpenSSL, Argon2id, database server version, HTTPS, `wp-config.php` permissions, Astraea master-key source, historical-keyring storage, and `display_errors`. Public installation over HTTP is a blocking condition; local/private installation may warn rather than fail. Unknown/unavailable evidence is not displayed as verified.

## Database and configuration hardening

- randomized table prefix by default;
- database password is preserved byte-for-byte;
- WordPress authentication salts are generated locally with CSPRNG entropy;
- installation does not call the WordPress.org salt endpoint;
- generated `wp-config.php` is restricted to owner-only permissions where the filesystem supports them;
- where a safe parent path exists, Secure Genesis provisions an external secret directory outside the webroot (`0700`) with Astraea master-key and historical-keyring files (`0600`).

## Astraea Master and ThroneGuard

The first account is provisioned as the Astraea Master. Master passwords are validated server-side, are not trimmed or normalized, accept long passphrases, reject control/NUL input, and enforce secure processing bounds.

ThroneGuard is mandatory during Secure Genesis. A 256-bit CSPRNG recovery key is shown once. Only an Argon2id hash is persisted. If post-core recovery-key provisioning fails after WordPress core installation, the transaction remains resumable and requires the operator to re-enter the saved key rather than storing plaintext server-side.

## GeDefense profiles

Secure Genesis compiles one of four profiles into the existing GeDefense configuration model:

- **Balanced:** first-party perimeter, rate protection, behavioral telemetry, Titan baseline, Airlock/Nemesis/Styx audit and ThroneGuard.
- **Hardened:** Balanced plus stricter Aegis/Titan policy and Morpheus.
- **Maximum:** Hardened plus Morpheus enforcement, application lockdown and additional kernel/filesystem restrictions.
- **Custom:** individual controls, while ThroneGuard remains mandatory.

Hades is intentionally not activated during Genesis. It is deferred until after the first verified login because a stealth/routing layer is an unnecessary bootstrap lockout risk.

## Anti-lockout

Only the direct TCP peer (`REMOTE_ADDR`) is considered during installation; forwarding headers are not trusted by default. If Secure Genesis itself adds an installer-peer exception, the exact additions are recorded. After a healthy first-boot verification they are removed. On a degraded boot they remain temporarily so a faulty hardening configuration does not irreversibly lock out the operator.

## Verification model

Install-time verification checks persisted security state. First normal boot separately verifies that configured module classes/runtime components actually loaded. This prevents configuration options alone from becoming green security telemetry.

## Known live-test gates

Source-level and deterministic verification do not replace a live MySQL/web-server/browser installation matrix. A release claim still requires fresh-install, resume-after-failure, proxy/topology, first-login, and deliberate anti-lockout tests on representative hosting environments.
