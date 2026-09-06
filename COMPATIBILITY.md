# AstraeaOS WP Compatibility Guide

<!-- STATUS: DIAMANT VGT SUPREME -->

AstraeaOS WP is an independent, hardened WordPress distribution with its own first-party kernel, built on top of the **WordPress 7.1** upstream base.

The overarching design directive is **"WordPress without the Plugin Stack."** Astraea delivers universal infrastructure tasks directly as first-party kernel modules, reducing the typical plugin footprint from 15–25 third-party plugins down to 0 for core tasks, while preserving complete compatibility for specialized business applications.

---

## 1. Native Plugin Replacement Matrix

AstraeaOS WP replaces universal infrastructure plugins with zero-overhead, first-party kernel systems. The table below details common third-party plugins and their native Astraea replacements:

| Capability / Category | Replaced Third-Party Plugins | AstraeaOS WP First-Party System | Security / Performance Advantage |
| :--- | :--- | :--- | :--- |
| **Perimeter WAF & Security** | Wordfence, iThemes Security, Sucuri, All In One WP Security | `Astraea GeDefense` | Multi-tier L0–L3 perimeter defense, XDR security fabric, zero external pingbacks, zero database query on L0 IP drops. |
| **Backup & Rollback** | UpdraftPlus, BackWPup, Duplicator, All-in-One WP Migration | `Astraea Vault` | Immutable `.avb` snapshot engine, pre-update atomic snapshots, post-update health verification, automatic rollback on boot failure. |
| **Consent & Privacy** | Borlabs Cookie, Complianz, Cookiebot, Cookie Notice | `Astraea VLP Light` | Kernel-native DOM resource gatekeeper, HMAC-authenticated HttpOnly consent receipts, local scanner, zero CDN/cloud calls. |
| **SMTP & Mail Delivery** | WP Mail SMTP, Easy WP SMTP, Post SMTP | `Astraea Mail Gateway` | AEAD encrypted credentials at rest, native XOAUTH2 (M365/Outlook), TLS 1.2/1.3 pinned transport, metadata endpoint filtering. |
| **Page Caching** | WP Super Cache, W3 Total Cache, WP Rocket, Cache Enabler | `Astraea Performance Engine` | Locked-write disk page caching, VLP consent variation awareness, logged-in user bypass, purge on publish/comment. |
| **Image & Upload Security** | Safe SVG, SVG Support, EWWW, WebP Express | `Astraea Media Engine` | XML-based SVG sanitizer, polyglot rejection, integer-constant MIME cross-check, GD re-encode, AVIF/WebP capability detection. |
| **Redirect Management** | Redirection, Safe Redirect Manager, 301 Redirects | `Astraea Redirect Manager` | ReDoS-immune matching, loop/chain detection up to depth 5, automatic post slug watcher, privacy-preserving 404 monitor. |
| **Technical SEO & Schema** | Yoast SEO, Rank Math, All in One SEO, SEOPress | `Astraea SEO Essentials` | Clean canonicals, OpenGraph/Twitter cards, JSON-LD Schema (WebSite, Org, Article), conflict detector auto-suppresses on 3rd party plugins. |
| **Contact Forms** | Contact Form 7, WPForms Lite, Ninja Forms | `Astraea Forms Light` | Accessible glassmorphism forms, CSRF + Honeypot + IP rate limiter, AEAD encrypted submission storage, Mail Gateway dispatch. |
| **WP-Cron Telemetry** | WP Crontrol, Advanced Cron Manager | `Astraea Task Center` | Scheduled task telemetry, delay detection (>10m alert), high-frequency warning, manual task trigger without external tools. |
| **Database Cleanup** | WP-Optimize, Advanced Database Cleaner, WP-Sweep | `Astraea Database Maintenance` | Dry-run byte analysis, revision/spam/transient pruner, mandatory pre-cleanup Vault snapshot, Step-Up verification. |
| **Maintenance / Coming Soon** | Under Construction, SeedProd, WP Maintenance Mode | `Astraea Maintenance Mode` | SEO-safe 503 + Retry-After, secret query/cookie bypass for clients, admin bypass, standalone zero-CDN template. |
| **Session & Identity** | WP 2FA, Two-Factor, WP Activity Log | `Astraea Identity Center` | Active session inspector & remote termination, IP-hashed login history, Argon2id passwords, MFA recovery codes. |

---

## 2. Granular Compatibility Layer (`astraea-core/Compatibility/`)

AstraeaOS WP incorporates the **"Minimum Effective Exception"** doctrine. Rather than globally disabling security hardening layers to support a legacy plugin, administrators can toggle granular compatibility flags via **Astraea Tools -> Compatibility Layer**:

1. **`LEGACY_XMLRPC`**: Permits XML-RPC requests (disabled by default to prevent brute-force amplification).
2. **`RELAX_REST_AUTH`**: Permits unauthenticated REST enumeration of users (disabled by default to prevent username harvesting).
3. **`ALLOW_PHP_MAILER`**: Permits unencrypted PHP mail fallback when SMTP is not configured.
4. **`LEGACY_PERMALINKS`**: Allows plain query-string permalinks (`/?p=123`).
5. **`UNFILTERED_SVGS`**: Permits unfiltered SVG uploads for trusted administrators (caution advised).
6. **`DISABLE_NONCE_STRICT`**: Relaxes strict session-bound nonce validation for legacy AJAX plugins.

---

## 3. Host Environment Requirements

| Runtime Component | Minimum Supported | Recommended Baseline | Hard Invariant |
| :--- | :--- | :--- | :--- |
| **PHP Version** | 8.3.0 | 8.4.x / 8.5.x | **PHP 8.3+ 64-bit strictly required**. 32-bit builds fail closed. |
| **Database Engine** | MySQL 8.0.36+ / MariaDB 10.11+ | MySQL 8.4+ / MariaDB 11.4+ | InnoDB engine, `utf8mb4_unicode_520_ci`. |
| **Mandatory Extensions** | `sodium`, `openssl`, `hash`, `json`, `mbstring`, `intl` | `gd`, `zip`, `dom`, `libxml`, `curl` | Missing extensions abort boot during Phase A. |
| **Web Server** | Nginx / Apache 2.4+ / Caddy | Nginx with fastcgi_pass | `.htaccess` or Nginx blocks on `astraea-vault-snapshots`. |

---

## 4. Upstream Backward Compatibility

- **Theme Compatibility**: All standard block themes (Full Site Editing) and classic PHP themes function out of the box.
- **Hook & Filter Parity**: All standard WordPress action and filter hooks (`init`, `wp_head`, `wp_footer`, `template_redirect`, `save_post`, `the_content`, etc.) operate with standard priority contracts.
- **Pluggable Functions**: Pluggable overrides (`wp_hash_password`, `wp_check_password`, `wp_set_password`) are intercepted by Astraea Crypto for Argon2id migration while guaranteeing 100% verification compatibility with existing MD5/phpass hashes.
