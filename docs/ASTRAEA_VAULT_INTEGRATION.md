# Astraea Vault — Core-Native Integration

## Location

Vault ships once, under:

`astraea-core/Vault/`

It is a first-party Astraea subsystem. A `wp-content/plugins/astraea-vault/` runtime copy is neither required nor shipped.

## Boot position

`BootOrchestrator::bootPhaseD()` runs after database/options/multisite/plugin-directory initialization and immediately before WordPress loads MU, network or ordinary plugins.

Phase D:

1. defines core-native Vault paths/version,
2. registers the Vault autoloader,
3. idempotently verifies/installs Vault DB schema and protected local storage,
4. starts `FatalMonitor`,
5. evaluates pending recovery through `RecoveryManager`,
6. returns control to WordPress plugin loading.

Normal Vault services and UpdateGuard registration occur in Phase E.

## Backup container

`.avb` is Astraea Vault's own authenticated binary record container; it is not described as a normal TAR/ZIP archive.

Security properties include:

- AES-256-GCM record encryption,
- random per-backup data key,
- envelope key wrapping,
- authenticated record ordering/context,
- encrypted manifest,
- explicit final record/truncation detection,
- streaming processing to avoid whole-backup RAM buffering.

A repository entry is considered verified only after the verification service has authenticated the container and persisted status `verified`.

## Update protection

For protected plugin updates:

`UpdateGuard::beforeInstall()` → service-key unlock → plugin-scoped snapshot → verification → update transaction record → WordPress updater.

After update, the transaction enters `awaiting_healthcheck`. Fatal monitoring can correlate a subsequent failure with the updated plugin. A high-confidence pending recovery is evaluated on the next Phase-D boot before third-party plugin code executes.

## Recovery

`RecoveryManager::maybeRecover()` restores the verified plugin snapshot using the unattended service key. On success it blocks the failed version from repeated automatic update and records the incident.

If rollback itself fails, quarantine removes activation state from:

- `active_plugins`, and
- `active_sitewide_plugins` on Multisite.

MU plugins are not silently rewritten merely because a failure occurred; uncontrolled MU-plugin mutation is outside this automatic quarantine contract.

The system does not promise zero downtime. It promises controlled pre-plugin recovery when evidence and a valid snapshot are available.

## Manual restore

Manual restore requires:

- WordPress capability,
- CSRF nonce,
- current-session Step-Up authentication,
- Vault passphrase authorization,
- pre-restore safety backup,
- safety backup verification,
- staging and integrity verification before live apply.

A failed live apply attempts a recovery using the verified safety snapshot.

## Core-native installation

`Installer::ensureCoreInstalled()` is idempotent and runs in Phase D. It verifies PHP/OpenSSL/Sodium requirements, creates/validates Vault tables and initializes private storage. Capabilities are provisioned separately during normal lifecycle so pre-plugin recovery does not depend on WordPress role initialization.

## Security events

Vault emits sanitized `astraea_vault_security_event` actions. `SecurityEventBridge` maps these into the Astraea evidence-only audit trail. Vault events are never fabricated to populate an empty dashboard.
