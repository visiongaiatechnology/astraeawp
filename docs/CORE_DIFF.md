# AstraeaOS WP Core Diff Specification

**Status:** DIAMANT VGT SUPREME  
**Base Version:** WordPress 7.1 (de_DE)  
**Distribution Version:** AstraeaOS WP 0.3.0-alpha  
**Philosophy:** Minimal Upstream Invasiveness, Deterministic Maintainability  

---

## 1. Architectural Philosophy

AstraeaOS WP adheres to the principle of **Maximum Upstream Preservation via Surgical Hook Points**. Rather than fragmenting WordPress core across hundreds of files, Astraea isolates distribution-specific functionality in self-contained namespaces under `astraea-core/`, including the core-native `astraea-core/Vault/` subsystem.

Modifications to upstream WordPress core files are strictly restricted to 2 files:
1. `wp-settings.php`: Boot sequence orchestration and autonomous recovery gating.
2. `wp-includes/version.php`: Minimum environment enforcement and distribution identity.
3. (Asset Addition): `wp-admin/images/astraea-logo.png`: Native optimized branding icon.

This minimal footprint reduces upstream rebase risk. Every future WordPress rebase must still be reviewed and tested; zero merge conflicts are not assumed.

---

## 2. Exhaustive Core Diff

### 2.1 File: `wp-settings.php`

#### Patch Point 1: Phase B Core Hook (Line 134-138)
* **Location:** Immediately after translation subsystem loading (`class-wp-translation-file-php.php`).
* **Purpose:** Initializes core runtime error traps, security baseline headers, and memory sanity controls before procedural helper libraries initialize.
* **Diff:**
```diff
--- a/wp-settings.php
+++ b/wp-settings.php
@@ -131,6 +131,11 @@ require ABSPATH . WPINC . '/l10n/class-wp-translation-file.php';
 require ABSPATH . WPINC . '/l10n/class-wp-translation-file-mo.php';
 require ABSPATH . WPINC . '/l10n/class-wp-translation-file-php.php';
 
+// AstraeaOS WP: Phase B (WordPress Core Ready)
+if ( class_exists( '\\Astraea\\Bootstrap\\BootOrchestrator' ) ) {
+	\Astraea\Bootstrap\BootOrchestrator::bootPhaseB();
+}
+
 /**
  * @since 0.71
```

#### Patch Point 2: Phase D Pre-Plugin Recovery Gate (Line 515-525)
* **Location:** Immediately after `$GLOBALS['wp_plugin_paths'] = array();`, before `wp_get_mu_plugins()`.
* **Purpose:** Executes the Astraea Vault autonomous rollback and quarantine circuit breaker before any third-party code can execute.
* **Diff:**
```diff
--- a/wp-settings.php
+++ b/wp-settings.php
@@ -507,6 +512,17 @@ wp_plugin_directory_constants();
  */
 $GLOBALS['wp_plugin_paths'] = array();
 
+// AstraeaOS WP: Vault autonomous recovery gate & Phase D.
+// DB/options/multisite are ready here, but no MU/network/normal plugin has loaded yet.
+if ( class_exists( '\\Astraea\\Bootstrap\\BootOrchestrator' ) ) {
+	\Astraea\Bootstrap\BootOrchestrator::bootPhaseD();
+}
+$astraea_vault_early = WP_PLUGIN_DIR . '/astraea-vault/integration/astraea-core-early-bootstrap.php';
+if ( is_file( $astraea_vault_early ) ) {
+	require_once $astraea_vault_early;
+}
+unset( $astraea_vault_early );
+
 // Load must-use plugins.
 foreach ( wp_get_mu_plugins() as $mu_plugin ) {
```

---

### 2.2 File: `wp-includes/version.php`

#### Patch: Environment Requirements and Distribution Constant
* **Location:** Lines 40, 50-54, 61, 68-70.
* **Purpose:** Enforce PHP 8.3+, MySQL 8.0+, mandatory crypto extensions (`sodium`, `openssl`, `mbstring`, `intl`), and declare `ASTRAEA_VERSION`.
* **Diff:**
```diff
--- a/wp-includes/version.php
+++ b/wp-includes/version.php
@@ -40,11 +40,15 @@ $tinymce_version = '49110-20250317';
-$required_php_version = '7.2.5';
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
 
+/**
+ * AstraeaOS Fork Version.
+ */
+if ( ! defined( 'ASTRAEA_VERSION' ) ) {
+	define( 'ASTRAEA_VERSION', \Astraea\Version::VERSION );
+}
```

---

## 3. Core Isolation Analysis

| Core Directory | Upstream Files Modified | New Astraea Files Added | Status |
| :--- | :--- | :--- | :--- |
| `/` (Root) | `wp-settings.php` (2 hooks) | `astraea-bootstrap.php` | Fully Isolated |
| `/wp-includes/` | `version.php` (Constants) | None | Preserved |
| `/wp-admin/` | None | `images/astraea-logo.png` | Standard Hooks Used |
| `/astraea-core/` | None (New subsystem) | All Astraea Core Classes | 100% Isolated |
| `/wp-content/plugins/` | None | `astraea-vault/` | Standard Plugin API |

---

## 4. Upstream Rebase Runbook

To merge future upstream WordPress updates:
```bash
# 1. Fetch upstream tag
git remote add upstream https://github.com/WordPress/WordPress.git
git fetch upstream --tags

# 2. Rebase Astraea release branch onto upstream tag
git checkout release/0.3.0-alpha
git rebase --onto 7.1.1 7.1.0

# 3. Verify that only wp-settings.php and version.php produce potential diffs
git diff --stat upstream/7.1.1

# 4. Run automated test suite
php tests/run_tests.php
```
