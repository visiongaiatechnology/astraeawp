# AstraeaOS WP — Legacy Audit & Surface Modernization

**Distribution:** AstraeaOS WP `0.1.0-alpha`  
**Base:** WordPress Core 7.1 de_DE  
**Architectural Objective:** A hardened, lightweight, and modern distribution stripping decades of obsolete baggage while preserving ecosystem compatibility.

---

## 1. Classification Matrix

| Category | Definition | Action in AstraeaOS WP |
| :--- | :--- | :--- |
| **A — REMOVE** | Obsolete functionality with zero modern value in PHP 8.3+ environments. | Pruned or bypassed. |
| **B — DISABLE BY DEFAULT** | High-risk legacy surface preserved for compatibility, but deactivated out of the box. | Disabled by default; explicit opt-in toggle. |
| **C — COMPATIBILITY LAYER** | Required for third-party plugin/theme interop; preserved behind clean facades. | Kept with deprecation notices where applicable. |
| **D — REFACTOR** | Fundamental core functionality built on outdated or insecure patterns. | Re-engineered with modern primitives. |
| **E — DEFER** | Complex subsystem requiring dedicated multi-phase overhaul. | Scheduled for Phase 2/3. |

---

## 2. Detailed Subsystem Audit

### 2.1 XML-RPC (`xmlrpc.php`)
- **Classification:** **B — DISABLE BY DEFAULT**
- **Rationale:** Historically the #1 attack vector for credential brute-forcing (amplified multithreaded `system.multicall`) and reflection DDoS. Modern applications exclusively interact via the REST API or GraphQL.
- **AstraeaOS Action:**
  - Standard execution terminates with `403 Forbidden`.
  - Filter `xmlrpc_enabled` defaults to `false`.
  - Re-activation enabled via `define('ASTRAEA_ENABLE_XMLRPC', true);`.

### 2.2 Pingbacks & Trackbacks
- **Classification:** **B — DISABLE BY DEFAULT**
- **Rationale:** Built in the early 2000s for blog-to-blog cross-notification. Functions as an unauthenticated SSRF vector and amplification tool against external servers.
- **AstraeaOS Action:**
  - Actions `do_all_pingbacks` and `do_all_trackbacks` unhooked.
  - `X-Pingback` HTTP header stripped from all responses.
  - Re-activation enabled via `define('ASTRAEA_ENABLE_PINGBACKS', true);`.

### 2.3 Core Emojis Script & Styles
- **Classification:** **B — DISABLE BY DEFAULT**
- **Rationale:** WordPress injects render-blocking inline JavaScript and CSS on `wp_head` and `admin_print_scripts` along with DNS prefetch hints to `s.w.org` to polyfill emoji rendering for obsolete browsers (e.g. Windows XP, IE11). All modern operating systems and browsers natively render Unicode emojis.
- **AstraeaOS Action:**
  - Inline scripts and styles unhooked.
  - TinyMCE `wpemoji` plugin unregistered.
  - Saves 5-15 KB of HTML payload and eliminates external DNS queries.
  - Re-activation enabled via `define('ASTRAEA_ENABLE_EMOJIS', true);`.

### 2.4 Obsolete PHP & Library Polyfills (`compat.php`, `sodium_compat/`)
- **Classification:** **A — REMOVE / BYPASS**
- **Rationale:**
  - Upstream contains ~70 files in `wp-includes/sodium_compat/` (ParagonIE pure-PHP bitwise libsodium emulation) created for PHP 5.2–7.1.
  - `wp-includes/compat.php` defines polyfills for `str_contains()`, `str_starts_with()`, `str_ends_with()`, `array_is_list()`, `mb_strlen()`, etc.
  - In AstraeaOS WP, the runtime baseline guarantees PHP >= 8.3 with native `ext-sodium` and `ext-mbstring`.
- **AstraeaOS Action:**
  - Native C-level engine routines are executed directly.
  - Eliminates thousands of redundant lines of code from opcode cache.

### 2.5 Password Hashing Architecture (`phpass`, MD5)
- **Classification:** **D — REFACTOR**
- **Rationale:** Upstream historically used `phpass` (portable MD5-based iteration from 2005) and introduced `$wp`-prefixed Bcrypt in 6.8. Bcrypt is memory-free and vulnerable to ASIC/FPGA hardware attacks.
- **AstraeaOS Action:**
  - Full replacement with native `PASSWORD_ARGON2ID` via `Astraea\Auth\PasswordService`.
  - Transparent on-the-fly migration ("Verify Legacy → Rehash Modern") upon login.
  - Optional server-side pepper support outside database.

### 2.6 Application Cryptography (`VIS_Vault`, OpenSSL)
- **Classification:** **D — REFACTOR**
- **Rationale:** Historical scattered direct calls to OpenSSL without authentication tags (AEAD) or domain-separated keys.
- **AstraeaOS Action:**
  - Central `Astraea\Crypto\CryptoService` with native libsodium `XChaCha20-Poly1305` and OpenSSL `AES-256-GCM`.
  - Additional Authenticated Data (AAD) binding to prevent ciphertext moving.
  - Master key never stored in the database.

### 2.7 In-Browser Code Editors (`theme-editor.php`, `plugin-editor.php`)
- **Classification:** **B — DISABLE BY DEFAULT**
- **Rationale:** Allows any compromised administrator account or CSRF attack to immediately achieve Remote Code Execution (RCE) by editing `.php` files through the admin interface.
- **AstraeaOS Action:**
  - `DISALLOW_FILE_EDIT` enforced as `true` by default.

### 2.8 Discovery & Fingerprinting Headers (RSD, WLW, WP Generator)
- **Classification:** **B — DISABLE BY DEFAULT**
- **Rationale:** Windows Live Writer manifest (`wlwmanifest.xml`) and RSD (`xmlrpc.php?rsd`) are relics of 2000s desktop blogging tools. Exposing `wp_generator` reveals exact software versions to automated vulnerability scanners.
- **AstraeaOS Action:**
  - Stripped from `wp_head`.

### 2.9 Admin-AJAX & Heartbeat
- **Classification:** **C — COMPATIBILITY LAYER**
- **Rationale:** Core UI autosave and post-lock depend on Heartbeat; countless legacy plugins rely on `admin-ajax.php`.
- **AstraeaOS Action:**
  - Preserved for full plugin compatibility.
  - Hardened with nonce lifetime reduction and GeDefense rate inspection.

### 2.10 REST API
- **Classification:** **C — COMPATIBILITY LAYER**
- **Rationale:** Modern block editor (Gutenberg) and headless installations depend entirely on the REST API.
- **AstraeaOS Action:**
  - Fully preserved and protected by GeDefense WAF rules.

### 2.11 Full Glassmorphism Admin UI Redesign
- **Classification:** **E — DEFER**
- **Rationale:** Comprehensive redesign belongs in Phase 2 after the Core Foundation has stabilized.
