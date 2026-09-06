# AstraeaOS WP — Upstream Maintenance & Patching Strategy

**Distribution:** AstraeaOS WP `0.1.0-alpha`  
**Base:** WordPress Core 7.1 de_DE  

---

## 1. Upstream Isolation Principle

A core architectural danger of maintaining a software fork is "patch drift" — making diffuse, sprawling changes across thousands of files, making it impossible to cleanly pull future upstream security patches.

AstraeaOS WP avoids this trap through **strict surgical isolation**:
- **99% of all new code** resides exclusively in `/astraea-core/`.
- Core WordPress files are modified **only at designated, minimal lifecycle anchor points**.

---

## 2. Upstream Core Modifications Inventory

Only **two** upstream WordPress files have been modified in AstraeaOS WP:

| Modified Core File | Lines Changed | Rationale & Modification |
| :--- | :--- | :--- |
| `wp-settings.php` | +5 lines | Injects the Astraea Core ignition hook immediately after `wp_initial_constants()`. Mounts autoloader, baseline checks, and pre-flight defenses. |
| `wp-includes/version.php` | ~12 lines | Updates `$required_php_version` to `'8.3'`, `$required_mysql_version` to `'8.0'`, adds required modern extensions (`sodium`, `openssl`, `mbstring`, `intl`), and defines `ASTRAEA_VERSION`. |

All other core modifications (Argon2id password hashing, legacy hash migration, HTTP headers, upload sanitation, legacy pruner) operate via native WordPress pluggable overrides and standard filter hooks.

---

## 3. Upstream Security Patch Ingestion Protocol

When WordPress releases upstream security patches (e.g. WordPress 7.1.1):

1. **Git Remote Synchronization:**
   ```bash
   git fetch upstream
   git checkout -b security-sync-7.1.1
   git merge upstream/7.1.1
   ```
2. **Conflict Resolution:**
   Because only `wp-settings.php` and `version.php` have minimal surgical hooks, merge conflicts are virtually non-existent.
3. **Automated Regression Verification:**
   ```bash
   php tests/run_tests.php
   php benchmarks/run_benchmarks.php
   ```
4. **Tag & Release:**
   Deploy updated AstraeaOS release with upstream patches incorporated.
