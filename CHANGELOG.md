# AstraeaOS WP Changelog

## 0.6.0-alpha — 2026-09-05

### First-Party Kernel Stack ("WordPress without the Plugin Stack")
- Added **Astraea Module Fabric** (`astraea-core/Modules/`): Directed Acyclic Graph (DAG) topological module boot sequence, Kahn's algorithm cycle detection, evidence-based live health probes (`ModuleHealth`), and Step-Up protected administrative control UI (`ModuleAdmin`).
- Added **Astraea Update Engine** (`astraea-core/Update/`): Cryptographic Ed25519 detached manifest verification, SHA-256 package validation, pre-update Vault snapshot, ZipSlip/symlink immune path-jailed extraction, atomic file swapping, and post-update rollback automation.
- Added **Upstream WordPress Shield** (`CoreUpdateGuard`): Intercepts `pre_set_site_transient_update_core` and blocks `api.wordpress.org` version checks to prevent unverified upstream core overwrites.
- Added **Autonomous Recovery Gate & Console** (`astraea-core/Recovery/` and `/astraea-recovery/`): Multi-tier boot crash loop prevention (engages gate after 3 consecutive boot failures), and standalone zero-WordPress-plugin emergency admin console with Master recovery key authentication.
- Added **WordPress → Astraea Migration Wizard** (`astraea-core/Migration/`): Safe onboarding flow with automated environment audit, 20+ redundant infrastructure plugin mapping, pre-migration Vault snapshot, and non-destructive deactivation.
- Added **Astraea Performance Engine** (`astraea-core/Performance/`): Disk-backed page cache with locked writes and VLP consent variation awareness, strict cache-control and immutable asset headers, native lazy-loading, adaptive heartbeat throttling, and database autoload profiler.
- Added **Astraea Media Engine** (`astraea-core/Media/`): Hardened multi-stage upload pipeline with integer-constant MIME cross-checking (`IMAGETYPE_*`), XML SVG sanitization stripping scripts and XXE, EXIF metadata stripping, GD re-encoding, and server WebP/AVIF capability detection.
- Added **Astraea Redirect Manager** (`astraea-core/Redirects/`): ReDoS-immune matching, loop/chain detection up to depth 5, automatic post slug change watcher, and privacy-preserving 404 monitor without raw IP storage.
- Added **Astraea SEO Essentials** (`astraea-core/SEO/`): Conflict-aware technical metadata, canonical URLs, OpenGraph/Twitter cards, JSON-LD Schema (WebSite, Org, Article), and sitemap enhancements. Automatically suppresses output when external SEO plugins are detected.
- Added **Astraea Forms Light** (`astraea-core/Forms/`): Accessible frontend form controls, honeypot spam trap, IP rate limiter, CSRF nonce protection, and authenticated AEAD encrypted submission storage under dedicated `FORMS_SUBMISSION` cryptographic domain.
- Added **Astraea Task Center** (`astraea-core/Tasks/`): WP-Cron and Astraea task inspection, delay detection (>10m warning), high-frequency schedule monitoring, and manual task execution triggers.
- Added **Astraea Database Maintenance** (`astraea-core/Database/`): Dry-run byte analysis, revision/spam/transient cleanup, mandatory pre-cleanup Vault snapshot, and Step-Up authentication.
- Added **Astraea Maintenance Mode** (`astraea-core/Maintenance/`): SEO-safe 503 maintenance mode with `Retry-After` headers, administrator bypass, secret cookie/query token bypass, and standalone zero-CDN glassmorphism template.
- Added **Astraea Identity Center** (`astraea-core/Auth/IdentityCenter.php`): Active session inspector, remote session revocation, IP-hashed login history, and truthful WebAuthn status reporting.
- Added **Astraea Compatibility Layer** (`astraea-core/Compatibility/`): Granular compatibility flags (`LEGACY_XMLRPC`, `RELAX_REST_AUTH`, `ALLOW_PHP_MAILER`) following the "minimum effective exception" doctrine.
- Added **WP-CLI Management Suite** (`astraea-core/CLI/AstraeaCommand.php`): CLI subcommands for `status`, `modules`, `health`, `integrity verify`, `vault list/verify`, `recovery status`, `update check/apply`, and `module enable/disable`.

### Secure Genesis
- Replaced the ordinary post-`wp_install()` success path with a transactional Astraea Secure Genesis security compilation flow.
- Added evidence-based installation preflight for runtime, crypto, database, HTTPS and external secret-storage readiness.
- Added randomized database prefix, byte-exact database credential handling, local CSPRNG WordPress salts and owner-only `wp-config.php` permissions.
- Added optional automatic master-key and historical-keyring files outside the webroot with `0700` directory / `0600` file permissions.
- Added Astraea Master first-user provisioning, mandatory ThroneGuard privilege separation and one-time 256-bit recovery-key enrollment with Argon2id-only persistence.
- Added Balanced, Hardened, Maximum and Custom GeDefense installation profiles; Hades is deferred until the first verified login to reduce lockout risk.
- Added direct-peer-only temporary Genesis anti-lockout and first-boot cleanup after verified healthy runtime state.
- Added resumable install states and recovery-key reprovisioning for interrupted post-core security commits.
- Added runtime first-boot verification so configured modules are not reported as verified merely because their options were persisted.

### GeDefense scanner precision
- Split scanner findings into `MALWARE`, `SUSPICIOUS`, `POLICY`, and `INFO`; numeric heuristic risk no longer automatically means malware.
- Blocking and quarantine now require explicit malware evidence rather than path context or parser warnings.
- Reworked path-context detection so source namespace directories such as `Vault/src/Backup/` are not treated as transient runtime directories.
- Added inert upload-guard recognition so owner-provided `index.php` deny guards are not mislabeled as upload malware.
- Reworked PHP/JavaScript lexical inspection to ignore dangerous-looking text inside comments and string literals while retaining request-to-exec and embedded-PHP detection in executable code.
- Reworked SVG triage so DOCTYPE metadata and parse failures are not treated as active payloads; real script/event/javascript/entity payloads remain malware evidence.
- Added regressions for the exact Astraea/WordPress false positives observed in Vault, WordPress JS workers/loaders, TinyMCE SVG fonts, dashboard SVGs and GeDefense tests.
- Removed an unnecessary `mbstring` dependency from GeDefense binary Vault framing by using byte-safe native `strlen()`/`substr()`.

## 0.5.0-alpha — 2026-09-05

### Astraea Mail Gateway
- Added kernel-native, zero-external-dependency SMTP configuration and routing.
- Added common provider presets plus fully editable Custom SMTP.
- Entire SMTP profile, credentials, diagnostics and delivery journal are encrypted with Astraea Crypto under dedicated `MAIL_TRANSPORT` domain separation.
- STARTTLS/SMTPS use strict certificate and hostname verification; plaintext SMTP is restricted to explicitly authorized private/local relays.
- Added endpoint SSRF policy, current-session Step-Up protection, encrypted diagnostics, circuit breaker and evidence-only delivery telemetry.
- Added optional DKIM signing using the PHPMailer runtime already bundled by WordPress; no external package is loaded.
- Invalid configured SMTP fails closed instead of silently falling back to PHP mail.
- Added native XOAUTH2 transport support without external OAuth libraries; Microsoft 365 and Outlook.com presets use Modern Auth.
- OAuth refresh tokens, client secrets and cached access tokens are encrypted with Astraea Crypto; environment/constant overrides remain available for secret-external deployments.
- OAuth token endpoints are HTTPS-only, redirect-disabled, unsafe/private-target checked, response-size bounded, and never persisted/logged in plaintext.
- Added strict STARTTLS adapter constrained to TLS 1.2/1.3 while reusing the PHPMailer runtime already bundled with WordPress.

## 0.4.0-alpha — 2026-09-05

- Added kernel-native **VLP (VisionLegalPro) Light Edition**.
- Added consent receipt authentication through Astraea Keyring with dedicated `VLP_CONSENT` domain separation.
- Added server-side WordPress HTML Tag Processor gate plus synchronous browser DOM insertion/property gate for optional third-party resources.
- Added editable consent service registry and bounded privacy scanner with filesystem path jails.
- Integrated **VGT Dattrack Light** behind explicit statistics consent, same-origin validation, rate limiting, daily pseudonymous HMAC identifiers and Astraea AEAD encryption.
- Added `Migration_002_VLPLight` and Astraea database schema version 1.1.0.
- Added VLP Glassmorphism backend and frontend privacy UI with zero external runtime dependencies.
- Added strict consent cache-safety mode and real Security Event Fabric integration.

# Changelog

## 0.3.1-alpha — 2026-09-05

### Astraea Glass Consistency Sweep
- Added a scoped zero-dependency Legacy Surface Adapter for WordPress core admin screens.
- Unified list tables, plugin screens, comments, dashboard metaboxes, settings forms, notices, screen options and legacy cards with Astraea Obsidian/Cyan glass tokens.
- Removed the remaining bright/white core surfaces visible inside the dark Astraea shell without DOM rewriting.
- Preserved WordPress/plugin functionality and accessibility semantics; visual adaptation is CSS-scoped to `body.wp-admin`.

### Astraea Installer Experience
- Rebuilt `setup-config.php` and `install.php` presentation with a dedicated local Astraea installer stylesheet.
- Added AstraeaOS WP branding, local logo, glass setup surfaces, responsive forms and installer-specific dark/cyan visual hierarchy.
- Replaced user-facing WordPress installer branding while preserving the underlying WordPress installation logic and translation/runtime APIs.
- No CDN, framework, remote font or JavaScript dependency added.


## [0.3.0-alpha] - 2026-09-05 (Stable-Alpha Hardening Candidate)

- Session-bound Step-Up authentication wired to privileged actions.
- Evidence-only Security Event pipeline connected to GeDefense, Vault, Auth, FileGuard and Integrity.
- Fake Passkey/WebAuthn readiness removed.
- Aegis DPI separated from Titan/HTTP policy; duplicate Astraea header emission suppressed when Titan is authoritative.
- Vault made fully core-native with idempotent schema/storage installation and Multisite network-plugin quarantine.
- Password pepper rotation migration support added.
- Local file integrity separated from optional Ed25519 release authenticity.
- Single Astraea distribution version source established.
- Runtime/source packaging split and release manifests regenerated only after code freeze.

All notable changes to **AstraeaOS WP** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [0.2.0-alpha] - 2026-09-04 (Phase 2: Glass Admin Experience)

### Added
- **Official Astraea Identity:** Integrated official cyan-metallic Astraea emblem across Topbar, Mission Control Center, and Authentication Portal (`astraea-logo.png`).
- **Complete WordPress Branding Eradication:**
  - Removed `— WordPress` from page titles (`admin_title`).
  - Purged `wp-logo`, `about`, `wporg`, `documentation`, and `support-forums` nodes from admin bar.
  - Replaced legacy footer credits with AstraeaOS WP & VisionGaiaTechnology build telemetry.
  - Removed upstream version disclosure checks in favor of system environment telemetry.
- **Astraea Glass Design System (`/astraea-core/AdminUI/assets/css/`):**
  - Modular token architecture (`astraea-tokens.css`) supporting 4-64px spacing, xs-full radius, and high-contrast dark/light typography.
  - 3-tier glassmorphism system (`astraea-glass.css`) with L1 (12px blur), L2 (20px blur), L3 (32px blur) and specular edge highlights with graceful non-backdrop fallbacks.
  - Reduced motion support (`prefers-reduced-motion: reduce`) eliminating transitions and animations for users with motion sensitivity.
- **Categorized Navigation Model (`NavigationAdapter`):**
  - Dynamically categorizes core and plugin menus into `CONTENT`, `DESIGN`, `SYSTEM`, `ASTRAEA`, and `EXTENSIONS`.
  - Preserves the standard `add_menu_page()` and `add_submenu_page()` registration paths through the Astraea navigation adapter.
  - Generates structured, resilient breadcrumbs with fallback hierarchies.
- **Keyboard-First Command Palette (`Ctrl+K` / `Cmd+K`):**
  - Global modal palette with fuzzy search across admin screens, navigation, system shortcuts, and content (`CommandRegistry`).
  - Full keyboard traversal ($\uparrow$, $\downarrow$, $\crarr$, $\text{ESC}$) and fast shortcuts (`G+D` for Dashboard, `C+P` for New Post).
  - Strict security model enforcing CSRF nonces (`wp_verify_nonce`) and capability checks (`current_user_can`).
- **Mission Control Center Dashboard (`ControlCenter`):**
  - Replaced legacy WordPress dashboard with modular glass telemetry cards.
  - Real-time Engine Telemetry (PHP, DB, Memory headroom, OpCache status, Server architecture).
  - GeDefense Multi-Layer Security HUD (Cerberus L0, Zeus Cryptography, Aegis Headers, Hades Guard, Pruned Legacy).
  - Live Content Pulse counters for Posts, Pages, Media, and Comments.
  - Dedicated full-page GeDefense Security screen (`index.php?page=astraea-security`).
- **Notification Center Drawer (`NoticeCenter`):**
  - Non-destructive notice adapter buffering `admin_notices` into glass toast cards and an off-canvas drawer.
  - Unread badge counter in topbar without causing layout shifts.
- **Glass Authentication Portal (`LoginTheme`):**
  - Redesigned `/wp-login.php` with deep space obsidian backdrop, ambient radial lighting, translucent glass card, and official glowing Astraea emblem.
- **Internal Component Preview Showcase (`ComponentPreview`):**
  - Dedicated admin showcase at `tools.php?page=astraea-components-preview` to inspect and test all buttons, inputs, pills, cards, and glass tiers.
- **Zero Runtime CDNs:** Astraea AdminUI assets are bundled locally with no required external script, style, or font CDN.
- **Historical Phase-2 UI checks:** superseded by the evidence-based release verification documented in `docs/TEST_REPORT.md`.

---

## [0.1.0-alpha] - 2026-09-04


### Added
- **Modern Runtime Baseline:** Enforced minimum requirements: PHP >= 8.3 (64-bit architecture), `ext-sodium`, `ext-openssl`, `ext-mbstring`, `ext-intl`, `ext-json`, MySQL >= 8.0 / MariaDB >= 10.11.
- **First-Party Layer (`/astraea-core/`):** Dedicated PSR-4 namespaced architecture (`Astraea\...`) isolated from core patch churn.
- **Argon2id Password Security:** Native `PASSWORD_ARGON2ID` password hashing with adaptive memory/time/threads cost (`Astraea\Auth\Argon2idPolicy`).
- **Transparent Password Migration:** "Verify Legacy → Rehash Modern" authentication interceptor automatically upgrading legacy MD5, phpass (`$P$`), and `$wp`-prefixed Bcrypt hashes to Argon2id upon login.
- **Server-Side Password Pepper:** Optional out-of-database secret pepper via `ASTRAEA_PASSWORD_PEPPER` with HMAC-SHA384 domain separation.
- **Cryptographic Core (`CryptoService`):** High-throughput AEAD encryption using native libsodium `XChaCha20-Poly1305` and OpenSSL `AES-256-GCM` with Additional Authenticated Data (AAD) binding and fail-closed error handling.
- **Master Key Architecture:** Out-of-database key management (`ASTRAEA_MASTER_KEY`) with HKDF-SHA256 (RFC 5869) context-isolated subkeys.
- **Secure Options API:** Encrypted application-level options storage via `astraea_secure_option_set()` and `astraea_secure_option_get()`.
- **Database Modernization:**
  - Composite B-tree index `post_id_meta_key (post_id, meta_key(191))` on `wp_postmeta`.
  - Composite B-tree index `user_id_meta_key (user_id, meta_key(191))` on `wp_usermeta`.
  - Modern `Connection` wrapper with prepared statements, parameter binding, and ACID transactions.
  - Versioned migration engine (`MigrationRunner`) tracking `astraea_db_version`.
- **Options Table Guard (`OptionsGuard`):** Byte budget monitor warning at > 800 KB autoload and automated transient cleanup.
- **Security Baseline:** Enforced `HttpOnly`, `Secure` over HTTPS, and `SameSite=Lax` cookies; generic login errors to prevent username enumeration; disabled built-in code editor.
- **Ingress Upload Security (`FileGuard`):** Magic-byte MIME detection via `finfo`, double extension blocking (`.php.jpg`), traversal sanitization, and SVG threat inspection.
- **HTTP Security Headers:** Injected HSTS, `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, and stripped `X-Powered-By`.
- **GeDefense First-Party Integration:** Integrated GeDefense Open Core (`v8.1.0`) into `/astraea-core/GeDefense/` with pre-flight boot execution (Cerberus L0, Zeus WAF, Aegis DPI, Hades gate) and vault unified with Astraea Crypto.
- **Request Profiler:** Development-mode profiler tracking boot time, peak memory, query count, and SQL duration.
- **Historical regression work:** current executable release checks and untested integration gates are documented in `docs/TEST_REPORT.md`.
- **Reproducible Benchmarks:** Automated benchmark suite measuring Argon2id, AEAD throughput, and L0 perimeter drops (`benchmarks/run_benchmarks.php`).
- **Technical Documentation:** Comprehensive architecture, security, cryptography, database, password migration, legacy audit, compatibility, and upstream strategy guides in `/docs/`.

### Changed
- Promoted minimum PHP version to 8.3 and MySQL version to 8.0 in `wp-includes/version.php`.
- Bypassed redundant pure-PHP polyfills (`sodium_compat`) in favor of native C-level engine routines.

### Deprecated / Disabled by Default
- **XML-RPC (`xmlrpc.php`):** Disabled by default with HTTP 403 response; opt-in via `ASTRAEA_ENABLE_XMLRPC`.
- **Pingbacks & Trackbacks:** Unhooked by default to eliminate SSRF vectors; opt-in via `ASTRAEA_ENABLE_PINGBACKS`.
- **Frontend Emojis:** Render-blocking inline JS/CSS scripts unhooked from `wp_head`; opt-in via `ASTRAEA_ENABLE_EMOJIS`.
