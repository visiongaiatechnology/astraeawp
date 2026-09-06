# AstraeaOS WP — Core Boot Graph & Phased Bootstrap Architecture

> **Specification:** Astraea Core Boot Sequence  
> **Status:** DIAMANT VGT SUPREME  
> **Target:** AstraeaOS WP 0.3.0-alpha  
> **Kernel Standard:** 2.1

---

## 1. The Boot Challenge: WordPress Core & Early Ingress

In standard WordPress, `wp-settings.php` loads in an imperative sequence over 800+ lines. Early execution requires strict discipline regarding when functions, hooks, databases, and options become available:

- At line 65: Only `wp_initial_constants()`, `load.php`, and `plugin.php` are available.
- Functions like `wp_normalize_path()`, `wp_mkdir_p()`, and `wp_die()` are defined in `wp-includes/functions.php` (Line 122).
- The Database object (`$wpdb`) is initialized around Line 140.
- The Options table and Object Cache are loaded around Line 400.
- Must-Use (MU) plugins are loaded at Line 511.
- Network and regular plugins are loaded between Lines 529 and 600.
- `plugins_loaded` fires at Line 635.
- `init` fires at Line 784.

Calling WordPress APIs before their defining files are required causes **deterministic fatal errors** (e.g. `Call to undefined function wp_normalize_path()`).

---

## 2. The Astraea Phased Bootstrap Protocol

To provide a verifiable, fail-closed bootstrap without disrupting upstream compatibility, Astraea divides its core initialization into **five decoupled phases**:

```text
┌─────────────────────────────────────────────────────────────────────────────┐
│ wp-settings.php                                                             │
├─────────────────────────────────────────────────────────────────────────────┤
│ 1. wp_initial_constants()                                                   │
│                                                                             │
│ ► ASTRAEA PHASE A: PRE-WORDPRESS MINIMAL                                    │
│   ├── Runtime Baseline Check (PHP 8.3+, 64-bit, sodium, openssl, mbstring)  │
│   ├── Autoloader Mount (PSR-4 for Astraea\*)                                │
│   ├── Cryptographic Keyring & Master Key Provider                           │
│   └── In-Memory Cerberus L0 Request Perimeter (Pure PHP)                   │
│                                                                             │
│ 2. wp-includes/functions.php & formatting.php                              │
│                                                                             │
│ ► ASTRAEA PHASE B: WORDPRESS CORE READY                                     │
│   ├── Procedural Cryptography & Pluggable Overrides (Argon2id)              │
│   ├── Password Migration Interceptor (authenticate filter)                  │
│   ├── Unified Header Policy Service (HSTS safe defaults, CSP)               │
│   ├── FileGuard Upload Quarantine Hook                                      │
│   ├── Legacy Surface Pruner (XML-RPC, Pingbacks, Emojis)                    │
│   └── GeDefense Phase 1 Engine Guard                                        │
│                                                                             │
│ 3. $wpdb & Options Table Initialized                                        │
│                                                                             │
│ ► ASTRAEA PHASE C: DATABASE & OPTIONS READY                                 │
│   ├── Schema Migrations (MigrationRunner)                                   │
│   ├── Secure Options Vault Engine                                           │
│   └── GeDefense Full Kernel Engagement                                      │
│                                                                             │
│ 4. $GLOBALS['wp_plugin_paths'] = array()                                    │
│                                                                             │
│ ► ASTRAEA PHASE D: PRE-PLUGIN RECOVERY GATE                                 │
│   ├── Astraea Vault Autonomous Recovery Engine                              │
│   ├── Incident Correlation & Broken Plugin Quarantine                       │
│   ├── Automatic Snapshot Rollback (Prior to MU/Network/Plugin execution)    │
│   └── Armed Fatal Monitor                                                   │
│                                                                             │
│ 5. Load MU Plugins, Network Plugins, Active Plugins                         │
│                                                                             │
│ ► ASTRAEA PHASE E: NORMAL ASTRAEA / ADMIN UI                                │
│   ├── Admin UI Bootstrap (Glass Shell, Navigation Adapter)                  │
│   ├── Command Palette Engine (Ctrl+K live search)                           │
│   ├── Mission Control Center & GeDefense Live Probes                        │
│   └── Internal Component Showcase                                           │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Phase Specifications

### Phase A: Pre-WordPress Minimal
- **Allowed Primitives:** Native PHP only (`defined()`, `getenv()`, `dirname()`, `realpath()`, `is_dir()`, `file_exists()`, `random_bytes()`, `extension_loaded()`).
- **Forbidden Primitives:** Zero `wp_*` functions, zero `add_action()`, zero `add_filter()`, zero database access.
- **Responsibility:** Ensure host environment meets minimum cryptographic and architectural baselines before continuing.

### Phase B: WordPress Core Ready
- **Prerequisites:** `wp-includes/functions.php`, `wp-includes/formatting.php`, `wp-includes/plugin.php`.
- **Allowed Primitives:** Core utilities (`wp_normalize_path()`, `wp_mkdir_p()`, `add_action()`, `add_filter()`).
- **Responsibility:** Hook security protections into WordPress filter queues prior to any database queries or plugin loading.

### Phase C: Database & Options Ready
- **Prerequisites:** `$wpdb` connected, `wp_options` table accessible, Object Cache primed.
- **Allowed Primitives:** Database queries, secure option storage, GeDefense persistent event logging.
- **Responsibility:** Database schema verification and state persistence.

### Phase D: Pre-Plugin Recovery Gate
- **Prerequisites:** Database ready; execution must occur **strictly before** `wp_get_mu_plugins()`, `wp_get_active_network_plugins()`, or `wp_get_active_and_valid_plugins()`.
- **Responsibility:** Astraea Vault inspects health state. If a broken plugin caused a fatal failure during update, Vault quarantines the plugin and restores the verified snapshot before it can crash the site.

### Phase E: Normal Astraea Lifecycle
- **Prerequisites:** Standard WordPress `plugins_loaded`, `init`, and `admin_init` lifecycle hooks.
- **Responsibility:** Administration UI, Command Palette, Notification Center, Real Health Probes.
