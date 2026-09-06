# Migration Guide — WordPress to AstraeaOS WP

<!-- STATUS: DIAMANT VGT SUPREME -->

This guide documents the procedures for migrating an existing standard WordPress installation (versions 6.4 through 7.1) to **AstraeaOS WP**.

AstraeaOS WP is designed for safe, non-destructive migration. Your existing content, themes, users, and specialized plugins remain completely intact. The migration focuses on consolidating fragmented infrastructure plugins into native Astraea First-Party Modules.

---

## 1. Pre-Flight Migration Checklist

Before beginning the migration, verify that the target hosting environment satisfies AstraeaOS baseline requirements:

- [ ] **PHP Version**: PHP 8.3.0 or higher (64-bit architecture mandatory).
- [ ] **PHP Extensions Loaded**: `sodium`, `openssl`, `hash`, `json`, `mbstring`, `intl`, `gd`, `zip`, `dom`, `libxml`.
- [ ] **Database Engine**: MySQL 8.0+ or MariaDB 10.11+.
- [ ] **Database User Privileges**: `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `CREATE`, `DROP`, `ALTER`, `INDEX`.
- [ ] **Filesystem Permissions**: Write permissions to `wp-content/` and root `wp-config.php`.

---

## 2. The Migration Process

### Step 1: Deploy AstraeaOS WP Codebase
1. Replace core WordPress directories (`wp-admin/`, `wp-includes/`) and root loader files (`wp-settings.php`, `wp-login.php`, `index.php`) with the AstraeaOS WP distribution files.
2. Ensure the `astraea-core/` directory is present in the root folder.
3. Keep your existing `wp-config.php` and `wp-content/` directory intact.

### Step 2: Access the Astraea Migration Wizard
1. Log in to the WordPress Admin dashboard using your administrator credentials.
2. Navigate to **Tools -> Astraea Migration** (`wp-admin/tools.php?page=astraea-migration`).
3. Complete the **Session-Bound Step-Up Authentication** prompt to authorize privileged migration actions.

### Step 3: Automated Environment Scan & Snapshot
1. The **Environment Scanner** runs an automated check of PHP version, active extensions, database engine, and write permissions.
2. Before any modifications occur, the wizard automatically invokes **Astraea Vault** to generate an immutable pre-migration snapshot (`.avb` archive) containing your entire database and configuration state.
3. The generated snapshot receipt is recorded in the security audit log.

### Step 4: Redundant Plugin Replacement
1. The **Plugin Replacement Analyzer** scans all active plugins against the Astraea Native Replacement catalog (e.g. Wordfence, UpdraftPlus, WP Mail SMTP, WP Super Cache, Yoast, Contact Form 7, etc.).
2. You are presented with a detailed overview showing which plugins have native first-party replacements in AstraeaOS WP.
3. Select which plugins you wish to deactivate.
   > **Note**: AstraeaOS WP *never* deletes third-party plugin files. Redundant plugins are safely deactivated so their data and configurations are preserved.

### Step 5: Finalization & Verification
1. Click **Execute Migration**.
2. Astraea enables the selected first-party modules (`gedefense`, `vault`, `vlp`, `mail`, `performance`, `media`, `redirects`, `seo`, `forms`, etc.).
3. The wizard runs post-migration health probes across all active modules to verify operational readiness.

---

## 3. Seamless Password Migration

AstraeaOS WP upgrades password hashing from legacy WordPress phpass/MD5 to **Argon2id** transparently:

1. Existing user passwords in `wp_users` remain unchanged during migration.
2. When a user logs in, Astraea's `PasswordMigrationManager` verifies the entered credentials against the legacy hash.
3. Upon successful verification, Astraea immediately re-hashes the password using native **Argon2id** (with optional server pepper) and updates the database record.
4. Users experience zero interruption and require no manual password reset.

---

## 4. Rollback & Disaster Recovery

If you need to roll back the migration for any reason:

### Option A: Via Admin UI (Astraea Vault)
1. Go to **Astraea -> Vault -> Snapshots**.
2. Select the pre-migration snapshot created in Step 3.
3. Authorize via Step-Up Authentication and click **Restore Snapshot**.

### Option B: Emergency Recovery Console (`/astraea-recovery/`)
1. If the site encounters a fatal conflict during migration, navigate directly to:
   `https://your-domain.example/astraea-recovery/`
2. Authenticate using your Master recovery key or administrator credentials.
3. Select **Restore Pre-Migration Snapshot** or **Emergency Plugin Disablement**.

### Option C: WP-CLI Rollback
```bash
# Check recovery telemetry
wp astraea recovery status

# Verify vault snapshots
wp astraea vault list
```
