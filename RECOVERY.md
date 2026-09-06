# Recovery & Continuity Guide — AstraeaOS WP

<!-- STATUS: DIAMANT VGT SUPREME -->

AstraeaOS WP includes an autonomous, multi-tier recovery architecture designed to prevent fatal boot loops, isolate broken plugins or updates, and guarantee continuous site accessibility even in catastrophic failure scenarios.

---

## 1. Autonomous Boot Failure Detection & Recovery Gate

AstraeaOS WP tracks every boot attempt starting at **Phase A** (pure PHP native initialization) through `BootFailureDetector`:

1. **Boot Tracking**: At ignition, an in-flight boot counter is incremented in a protected local state file.
2. **Deterministic Completion**: When Phase E completes normally and standard rendering begins, `markBootSuccessful()` clears the counter.
3. **Crash Loop Detection**: If a fatal error occurs (e.g. `E_ERROR`, `E_PARSE`, `E_CORE_ERROR`) or a process aborts before completing boot:
   - The shutdown handler records the sanitized fatal error metadata.
   - If **3 consecutive incomplete boots** occur within 10 minutes, Astraea engages the **Recovery Gate**.
4. **Recovery Gate Engagement**:
   - Frontend and admin traffic are automatically intercepted and directed to the recovery warning screen.
   - Administrators are guided to the isolated **Astraea Recovery Console**.

---

## 2. The Isolated Recovery Console (`/astraea-recovery/`)

The Recovery Console is completely decoupled from the WordPress theme, plugin, and admin subsystems. It operates in pure native PHP with zero third-party plugin dependencies.

### Accessing the Console
Navigate directly to:
```
https://your-domain.example/astraea-recovery/
```

### Authentication Modes
Access requires one of two privileged authentication methods:
1. **Master Recovery Key**: The cryptographic 256-bit emergency recovery key generated during **Secure Genesis** (verified against Argon2id hash).
2. **Administrator Credentials**: Database verification of administrator username and password using native Argon2id/phpass authentication.

### Available Recovery Operations

| Operation | Purpose | Mechanism |
| :--- | :--- | :--- |
| **Emergency Plugin Disablement** | Immediately resolves WSOD (White Screen of Death) caused by third-party plugins. | Atomically sets `active_plugins` to an empty array in the database; records list for later re-enabling. |
| **Emergency Module Disablement** | Disables a faulty first-party module. | Updates module state flag in options; critical kernel modules (`recovery`, `gedefense`, `vault`) remain protected. |
| **Restore Vault Snapshot** | Restores entire site to a verified pre-update or pre-migration snapshot. | Extracts verified `.avb` archive and executes database rollback. |
| **File Integrity Audit** | Detects corrupted, missing, or tampered core files. | Computes SHA-256 hashes of all runtime files and compares against `BUILD-MANIFEST.json`. |
| **Database Diagnostics** | Tests database connectivity and repair status. | Tests `$wpdb` credentials and runs MySQL table health queries. |
| **Sanitized Error Inspector** | Inspects the exact fatal error that triggered the recovery gate. | Displays error type, filename, and line number without exposing sensitive database credentials or environment secrets. |
| **Maintenance Lock Toggle** | Places the site in an SEO-safe 503 maintenance mode during repairs. | Activates maintenance mode bypass token for admin access. |

---

## 3. WP-CLI Recovery Interface

For systems with SSH/terminal access, WP-CLI provides rapid recovery commands:

```bash
# Check boot failure count and recovery gate status
wp astraea recovery status

# List registered modules and their health states
wp astraea modules

# Disable a problematic module
wp astraea module disable <module-id>

# Run full file integrity verification
wp astraea integrity verify

# Emergency TITAN WAF recovery (if locked out by security policy)
wp gedefense titan recover
```

---

## 4. Manual Emergency Recovery (Zero Server Access)

In the unlikely event that both the web recovery console and WP-CLI are unreachable:

1. **Disable Plugins via Filesystem**:
   - Temporarily rename `wp-content/plugins` to `wp-content/plugins_disabled`.
2. **Reset Boot Failure Counter**:
   - Delete or empty `wp-content/uploads/vgt-temp/boot_failures.json`.
3. **Trigger Safe Boot**:
   - Access `wp-login.php` to initiate a clean Phase A–E boot sequence.
