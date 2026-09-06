# AstraeaOS WP 0.6.0-alpha — Release Verification Report

**Classification:** VGT internal engineering status only; not an external certification.  
**Date:** 2026-09-05  
**Verification runner:** `tests/run_release_checks.php`  
**Execution environment:** PHP 8.4 CLI, Linux x86_64

## Executed release checks

The zero-dependency verification runner executes **98 checks with 98 PASS / 0 FAIL** against the actual shipped classes and source paths.

Verified areas include:

- Astraea distribution single-source versioning;
- Keyring rotation, durable historical-key persistence, DECRYPT_ONLY and REVOKED lifecycle;
- historical ciphertext after keyring reinitialization;
- Argon2id previous-pepper migration and password whitespace significance;
- session-bound Step-Up and privileged plugin/theme/Vault guards;
- evidence-only Security Event Fabric;
- Phase-D Vault recovery placement and Multisite network-plugin quarantine;
- real AVB round-trip, ciphertext tamper rejection and truncation rejection;
- kernel-native VLP Light Phase-E boot and database migration wiring;
- VLP dedicated `VLP_CONSENT` and `VLP_DATTRACK` cryptographic domains;
- HttpOnly/HMAC-authenticated consent receipt architecture;
- server-side `WP_HTML_Tag_Processor` resource gate;
- synchronous dynamic DOM/network/cookie gate;
- consent-gated Dattrack with no trusted proxy-IP headers and no raw-IP persistence field;
- scanner path jail, symlink rejection and bounded file processing;
- Step-Up enforcement on VLP policy/service mutation;
- no runtime CDN dependency and no dangerous dynamic HTML/eval sinks in AdminUI/Vault/VLP/Mail JavaScript;
- Astraea Mail kernel wiring, dedicated crypto domain and fail-closed transport configuration;
- strict TLS peer verification and TLS 1.2/1.3 STARTTLS adapter;
- SMTP metadata/private endpoint policy and public plaintext SMTP rejection;
- encrypted SMTP config round-trip with credential-byte preservation and plaintext-secret absence;
- Step-Up protection, encrypted delivery journal and no recipient/message-content persistence;
- Microsoft 365/XOAUTH2 preset wiring, HTTPS-only OAuth endpoint policy, redirect-disabled native token refresh, encrypted OAuth secret/cache storage and RFC-style bearer SASL payload;
- Secure Genesis transactional/resumable installation state;
- server-side Astraea Master password policy and byte-significant credential handling;
- mandatory ThroneGuard enrollment with Argon2id-only recovery-key persistence;
- direct-peer-only temporary anti-lockout with self-removal after verified healthy boot;
- randomized database prefix, owner-only `wp-config.php`, local CSPRNG salts and no WordPress.org salt dependency;
- external Astraea master-key/historical-keyring provisioning when a safe path is available;
- evidence-based Secure Genesis preflight and first-boot runtime verification;
- Hades deferred during Genesis to reduce first-login lockout risk;
- zero external UI dependencies in Secure Genesis.

## Core test suite

`tests/run_tests.php` executed **329 assertions with 0 failures** across Auth, Crypto, Database, Security, Compatibility, GeDefense, AdminUI, Vault, Session, and PublicRelease suites in the build environment. Expected negative-path diagnostics were emitted to the internal test log; they are not test failures.

Verified Public Release areas in `tests/PublicReleaseTest.php` include:
- Module Fabric DAG topological sorting, acyclic resolution, and dependency graph validation;
- Missing dependency fail-closed boot blocking and cycle detection;
- `ModuleHealth` status value objects, severity comparison, and serialization;
- Update manifest canonical serialization, Ed25519 signature verification, and anti-downgrade checks;
- SvgSanitizer active threat neutralization (embedded `<script>`, event handlers) and safe vector preservation;
- Redirect loop detection, hop limits, and ReDoS regex safety constraints;
- Forms Light authenticated AEAD submission payload encryption under domain `FORMS_SUBMISSION`;
- DiagnosticsBundle recursive deep credential and token redaction (`[REDACTED]`);
- Boot failure loop detector 3-failure threshold logic and emergency gate engagement.

## GeDefense scanner precision regressions

The scanner is tested separately with positive and negative controls. The following suites currently pass:

- `malware-scanner-regression.php` — PASS;
- `scanner-resumption-regression.php` — PASS;
- `integrity-baseline-regression.php` — PASS;
- `integrity-regression.php` — PASS across the current 27-component GeDefense integrity set.

The malware regression specifically protects against the false-positive classes observed in Astraea Vault backup source paths, WordPress `customize-loader.js`, the VIPS worker, TinyMCE SVG font assets, dashboard SVG assets and GeDefense test fixtures. It also contains positive controls proving that request-to-exec PHP, upload webshell behavior, active embedded PHP and active SVG script payloads continue to produce blocking malware evidence.

Scanner findings are explicitly classified as `MALWARE`, `SUSPICIOUS`, `POLICY`, or `INFO`. Numeric risk alone does not permit blocking or quarantine.

## Syntax verification

- **1906 / 1906 PHP files** in the final pre-package source tree: `php -l` PASS.
- **662 / 662 JavaScript files** in the final pre-package source tree: `node --check` PASS.

## Explicitly NOT TESTED in this container

These remain live test-server gates and are not reported as passing:

- fresh AstraeaOS WP Secure Genesis install against MySQL 8.0+ / MariaDB 10.11+;
- interrupted post-`wp_install()` Genesis resume on a real web-server/browser session;
- first-login ThroneGuard/GeDefense anti-lockout behavior behind representative reverse proxies and hosting panels;
- migration from an existing WordPress 7.1 database;
- complete browser lifecycle including Gutenberg/media;
- intentional broken-plugin update → fatal correlation → Phase-D rollback → successful next request;
- false-positive chaos scenario where Plugin A is updated while Plugin B fails;
- full Vault database + files backup/restore against live MySQL/MariaDB;
- Multisite failure/rollback in a live network;
- VLP browser matrix across current Chrome/Firefox/Safari/Edge;
- server-set third-party cookie remediation, which by design requires the originating PHP component to become consent-aware;
- live SMTP interoperability/authentication against external providers and real OAuth token refresh against provider endpoints.

## Release interpretation

This supports the label **AstraeaOS WP 0.6.0-alpha / Secure Genesis + GeDefense scanner precision candidate**. It does not constitute legal certification, external security certification, malware-detection certification, or universal compatibility proof.
