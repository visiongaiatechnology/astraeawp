# AstraeaOS WP Core Diff Specification

**Status:** DIAMANT VGT SUPREME  
**Base Version:** WordPress 7.1.5 (de_DE)  
**Distribution Version:** AstraeaOS WP 0.8.0  
**Philosophy:** Minimal Upstream Invasiveness, Deterministic Maintainability  

---

## 1. Architectural Philosophy

AstraeaOS WP adheres to the principle of **Maximum Upstream Preservation via Surgical Hook Points**. Rather than fragmenting WordPress core across hundreds of files, Astraea isolates distribution-specific functionality in self-contained namespaces under `astraea-core/`, including the core-native `astraea-core/Vault/` subsystem.

Modifications to upstream WordPress core files are restricted to the boot anchors, the version delegate, the first-run installer surfaces and branding assets. Everything else runs through native WordPress pluggable overrides and filter hooks, or lives entirely inside `astraea-core/`.

This minimal footprint reduces upstream rebase risk. Every future WordPress rebase must still be reviewed and tested; zero merge conflicts are not assumed.

---

## 2. Exhaustive Core Diff (WordPress 7.1.5 base)

### 2.1 File: `wp-settings.php` — 2 hook blocks

#### Patch Point 1: Phase A ignition + Phase B hook
* **Location:** Immediately after `wp_initial_constants()` and immediately after the translation subsystem (`l10n/class-wp-translation-file-php.php`).
* **Purpose:** Mounts the Astraea autoloader and baseline runtime checks before the fatal-error handler is registered, and initializes core runtime error traps, security baseline headers and memory sanity controls once the WordPress function surface exists.
* **Diff:**
```diff
--- a/wp-settings.php
+++ b/wp-settings.php
@@ wp_initial_constants();
+
+// AstraeaOS WP: Core Ignition & Security Foundation
+if ( file_exists( ABSPATH . 'astraea-core/astraea-bootstrap.php' ) ) {
+	require_once ABSPATH . 'astraea-core/astraea-bootstrap.php';
+}
 
 // Register the shutdown handler for fatal errors as soon as possible.
 wp_register_fatal_error_handler();
```
```diff
--- a/wp-settings.php
+++ b/wp-settings.php
@@ require ABSPATH . WPINC . '/l10n/class-wp-translation-file-php.php';
 
+// AstraeaOS WP: Phase B (WordPress Core Ready)
+if ( class_exists( '\\Astraea\\Bootstrap\\BootOrchestrator' ) ) {
+	\Astraea\Bootstrap\BootOrchestrator::bootPhaseB();
+}
+
 /**
  * @since 0.71
```

#### Patch Point 2: Phase D Pre-Plugin Recovery Gate
* **Location:** Immediately after `$GLOBALS['wp_plugin_paths'] = array();`, before `wp_get_mu_plugins()`.
* **Purpose:** Executes the Astraea Vault autonomous rollback and quarantine circuit breaker before any MU/network/normal plugin can execute.
* **Diff:**
```diff
--- a/wp-settings.php
+++ b/wp-settings.php
@@ $GLOBALS['wp_plugin_paths'] = array();
 
+// AstraeaOS WP: Vault autonomous recovery gate & Phase D.
+// DB/options/multisite are ready here, but no MU/network/normal plugin has loaded yet.
+if ( class_exists( '\\Astraea\\Bootstrap\\BootOrchestrator' ) ) {
+	\Astraea\Bootstrap\BootOrchestrator::bootPhaseD();
+}
+ 
 // Load must-use plugins.
 foreach ( wp_get_mu_plugins() as $mu_plugin ) {
```

### 2.2 File: `wp-includes/version.php` — environment requirements and identity

* **Location:** `$required_php_version`, `$required_php_extensions`, `$required_mysql_version`, trailing Astraea block.
* **Purpose:** Enforce PHP 8.3+, MySQL 8.0+, mandatory crypto extensions (`sodium`, `openssl`, `mbstring`, `intl`) and declare `ASTRAEA_VERSION` from the single Astraea version source.
* **Note:** `$wp_version` stays the untouched upstream value (`7.1.5`); the distribution version is `ASTRAEA_VERSION`.
* **Diff:**
```diff
--- a/wp-includes/version.php
+++ b/wp-includes/version.php
@@ $tinymce_version = '49110-20250317';
-$required_php_version = '7.4';
+$required_php_version = '8.3';
 
 $required_php_extensions = array(
 	'json',
 	'hash',
+	'sodium',
+	'openssl',
+	'mbstring',
+	'intl',
 );
 
-$required_mysql_version = '5.5.5';
+$required_mysql_version = '8.0';
+
+/**
+ * AstraeaOS Fork Version.
+ */
+if ( ! class_exists( '\Astraea\Version', false ) ) {
+	require_once dirname( __DIR__ ) . '/astraea-core/Version.php';
+}
+if ( ! defined( 'ASTRAEA_VERSION' ) ) {
+	define( 'ASTRAEA_VERSION', \Astraea\Version::VERSION );
+}
```

### 2.3 File: `wp-admin/setup-config.php` — Secure Genesis configuration step

* **Added:** `astraea_provision_install_secret_storage()` (owner-only `0700`/`0600` secret store outside the webroot), `astraea_inject_secret_config()` (`ASTRAEA_MASTER_KEY_FILE` / `ASTRAEA_KEYRING_FILE` before the "stop editing" marker).
* **Hardened:** local CSPRNG-only salts (no `api.wordpress.org/secret-key` fallback, fail closed), byte-exact database password, randomized `ast_xxxxxxxx_` table prefix, `0600` file mode for `wp-config.php` with rollback when owner-only permissions cannot be enforced, no `display_errors` leakage.
* **Branded:** AstraeaOS WP installer header/logo, `astraea-install.css`, Genesis copy.

### 2.4 File: `wp-admin/install.php` — Secure Genesis installation step

* **Added:** requires `astraea-core/Installer/SecureGenesis.php` and `wp-admin/includes/astraea-secure-genesis-view.php`, enqueues `wp-admin/js/astraea-secure-genesis.js` and `wp-admin/css/astraea-install.css`, ten-step Genesis UX, transactional `SecureGenesis::beginTransaction()` / `compile()` after `wp_install()`, resumable `SECURITY_FAILED` state instead of a silent success page.

### 2.5 Branding assets and offline readmes

* **Replaced:** `readme.html` and `liesmich.html` hold the AstraeaOS-specific offline readmes (requirement text: PHP 8.3+, MySQL 8.0+/MariaDB 10.11+). Upstream `license.txt` is retained unchanged.
* **Added:** `wp-admin/images/astraea-logo.png`, `wp-admin/css/astraea-install.css`, `wp-admin/js/astraea-secure-genesis.js`, `wp-admin/includes/astraea-secure-genesis-view.php`.

### 2.6 Explicitly NOT modified

* `wp-includes/default-constants.php` is **untouched**. AstraeaOS WP 0.8.0 ships the upstream default theme (`WP_DEFAULT_THEME` unchanged). Earlier distribution builds patched this to a project theme; that patch belongs to the SonaMedia build and was dropped in the split-off.

---

## 3. Core Isolation Analysis

| Core Directory | Upstream Files Modified | New Astraea Files Added | Status |
| :--- | :--- | :--- | :--- |
| `/` (Root) | `wp-settings.php` (2 hook blocks) | `astraea-bootstrap.php` (in `astraea-core/`), branded `readme.html`/`liesmich.html` | Fully Isolated |
| `/wp-includes/` | `version.php` (environment constants + version delegate) | None | Preserved |
| `/wp-admin/` | `install.php`, `setup-config.php` (Secure Genesis) | `images/astraea-logo.png`, `css/astraea-install.css`, `js/astraea-secure-genesis.js`, `includes/astraea-secure-genesis-view.php` | Installer-only |
| `/astraea-core/` | None (new subsystem) | All Astraea Core classes | 100% Isolated |
| `/wp-content/` | None | None (upstream themes/plugins only) | Upstream |

---

## 4. Upstream Rebase Runbook

To merge future upstream WordPress updates:

```bash
# 1. Fetch upstream tag
git remote add upstream https://github.com/WordPress/WordPress.git
git fetch upstream --tags

# 2. Rebase the Astraea release branch onto the upstream tag
git checkout release/0.8.0
git rebase --onto 7.1.6 7.1.5

# 3. Expect diffs only in the known patch surfaces
git diff --stat upstream/7.1.6 -- wp-settings.php wp-includes/version.php wp-admin/install.php wp-admin/setup-config.php

# 4. Run the automated test suite and release guards
php tests/run_tests.php
php tests/run_release_checks.php
php tools/build-release.php
```
