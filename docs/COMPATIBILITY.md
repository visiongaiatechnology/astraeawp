# AstraeaOS WP — Ecosystem Compatibility Strategy

**Distribution:** AstraeaOS WP `0.1.0-alpha`  
**Base:** WordPress Core 7.1 de_DE  

---

## 1. Three-Tier Compatibility Model

To preserve the rich WordPress ecosystem of themes and plugins while modernizing the core runtime, AstraeaOS WP defines three distinct compatibility layers:

```
┌────────────────────────────────────────────────────────┐
│               1. ASTRAEA NATIVE LAYER                  │
│  Modern PSR-4 APIs: CryptoService, SecureOptions,      │
│  Argon2idPolicy, Connection, MigrationRunner, GeDefense│
├────────────────────────────────────────────────────────┤
│               2. CORE COMPATIBLE LAYER                 │
│  Standard WordPress APIs: WP_Query, get_posts(),       │
│  $wpdb, REST API, Gutenberg Block Editor, Options API  │
├────────────────────────────────────────────────────────┤
│             3. COMPATIBILITY TOGGLES                   │
│  Opt-in flags for legacy surfaces: XML-RPC,            │
│  Pingbacks, Trackbacks, Frontend Emojis polyfills      │
└────────────────────────────────────────────────────────┘
```

---

## 2. Compatibility Toggles

Legacy surfaces that are disabled by default can be re-enabled individually in `wp-config.php`:

```php
// Re-enable XML-RPC protocol (e.g. for legacy mobile app support)
define('ASTRAEA_ENABLE_XMLRPC', true);

// Re-enable automatic Pingbacks and Trackbacks
define('ASTRAEA_ENABLE_PINGBACKS', true);

// Re-enable inline frontend emoji detection scripts and styles
define('ASTRAEA_ENABLE_EMOJIS', true);

// Re-enable built-in theme/plugin code editor
define('DISALLOW_FILE_EDIT', false);
```

---

## 3. Known Breaking Changes & Requirements

| Upstream Behavior | AstraeaOS WP Behavior | Migration / Remedy |
| :--- | :--- | :--- |
| **PHP 7.4 / 8.0 / 8.1 support** | Strictly requires **PHP >= 8.3** on 64-bit architecture. | Upgrade hosting environment to PHP 8.3, 8.4, or 8.5. |
| **XML-RPC Requests** | Requests to `xmlrpc.php` return HTTP `403 Forbidden`. | Migrate integrations to the WP REST API or enable `ASTRAEA_ENABLE_XMLRPC`. |
| **Pingbacks / Trackbacks** | Unhooked; no outbound or inbound pingbacks processed. | Modern trackback alternatives or enable `ASTRAEA_ENABLE_PINGBACKS`. |
| **Frontend Emoji Scripts** | Inline JS/CSS polyfill not printed on `wp_head`. | Modern browsers render emojis natively; or set `ASTRAEA_ENABLE_EMOJIS`. |
| **Theme / Plugin Editor** | `DISALLOW_FILE_EDIT` is `true` by default. | Edit files via SFTP, SSH, or CI/CD pipelines. |
| **Password Hashing** | New passwords hashed with Argon2id. | Transparent; legacy hashes are automatically converted upon login. |
