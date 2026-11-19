# AstraeaOS WP — Upstream Maintenance & Patching Strategy

**Distribution:** AstraeaOS WP `0.8.0`  
**Base:** WordPress Core 7.1.5 de_DE  

---

## 1. Upstream Isolation Principle

A core architectural danger of maintaining a software fork is "patch drift" — making diffuse, sprawling changes across thousands of files, making it impossible to cleanly pull future upstream security patches.

AstraeaOS WP avoids this trap through **strict surgical isolation**:
- **99% of all new code** resides exclusively in `/astraea-core/`.
- Core WordPress files are modified **only at designated, minimal lifecycle anchor points**.

---

## 2. Upstream Core Modifications Inventory

The distribution touches **four** upstream files, plus branding/additive assets:

| Modified Core File | Scope | Rationale & Modification |
| :--- | :--- | :--- |
| `wp-settings.php` | +13 lines, 2 blocks | Injects the Astraea Core ignition hook immediately after `wp_initial_constants()`, the Phase B hook after the l10n subsystem, and the Phase D Vault recovery gate immediately before MU plugins load. |
| `wp-includes/version.php` | ~14 lines | Sets `$required_php_version` to `'8.3'`, `$required_mysql_version` to `'8.0'`, adds required modern extensions (`sodium`, `openssl`, `mbstring`, `intl`), and defines `ASTRAEA_VERSION` from `astraea-core/Version.php`. `$wp_version` keeps the upstream value. |
| `wp-admin/install.php` | Secure Genesis surface | Boots the transactional Astraea Secure Genesis installer (ten-step UX, ThroneGuard/GeDefense compile, resumable failure state). |
| `wp-admin/setup-config.php` | Secure Genesis surface | CSPRNG-only salts, randomized table prefix, owner-only `wp-config.php` (`0600`), optional external master-key/keyring store. |

**Additive (no upstream behaviour changed):** `readme.html` / `liesmich.html` replaced with AstraeaOS offline readmes, plus `wp-admin/images/astraea-logo.png`, `wp-admin/css/astraea-install.css`, `wp-admin/js/astraea-secure-genesis.js`, `wp-admin/includes/astraea-secure-genesis-view.php`.

`wp-includes/default-constants.php` is **not** modified in 0.8.0 — the upstream default theme applies.

All other core behaviour (Argon2id password hashing, legacy hash migration, HTTP headers, upload sanitation, legacy pruner) operates via native WordPress pluggable overrides and standard filter hooks.

---

## 3. Upstream Security Patch Ingestion Protocol

When WordPress releases upstream security patches (e.g. WordPress 7.1.6):

1. **Git Remote Synchronization:**
   ```bash
   git fetch upstream
   git checkout -b security-sync-7.1.6
   git merge upstream/7.1.6
   ```
2. **Conflict Resolution:**
   Because only the four files above carry surgical hooks, conflicts are rare and localized. Verify that the Astraea hook blocks and the Genesis installer surfaces survived the merge — `tests/run_release_checks.php` asserts both.
3. **Automated Regression Verification:**
   ```bash
   php tests/run_tests.php
   php tests/run_release_checks.php
   php tools/build-release.php
   ```
4. **Version bookkeeping:**
   Bump `$wp_version` in `wp-includes/version.php` to the new upstream value and `Astraea\Version::WP_BASE` in `astraea-core/Version.php` in the same commit.
5. **Tag & Release:**
   Deploy the updated AstraeaOS release with upstream patches incorporated.

---

## 4. Current Rebase Record

| AstraeaOS WP | Upstream base | Upstream delta re-ingested |
| :--- | :--- | :--- |
| `0.8.0` | WordPress `7.1.5-de_DE` | `$wp_version` bump, regenerated block/editor/REST assets, REST/search handler and inter-activity API updates. No installer changes. |
| `0.7.0-sonamedia` | WordPress `7.1.4-de_DE` | initial 7.1.4 port (SonaMedia build; not part of this line) |
