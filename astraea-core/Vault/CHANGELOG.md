# Changelog

## 0.3.0-alpha — 2026-09-05

- Promoted Vault to a single core-native Astraea subsystem; removed the duplicate plugin-runtime path.
- Added idempotent Phase-D schema/storage initialization independent of plugin activation hooks.
- Added session-bound Astraea Step-Up enforcement to restore, download, delete, policy and key/passphrase-sensitive actions.
- Added evidence-only Security Event bridge into Astraea Security Center.
- Hardened Multisite quarantine to remove `active_sitewide_plugins` state when rollback itself fails.
- Exposed real early-recovery and UpdateGuard registration evidence to Security Center.
- Retained AVB format version independently from module/distribution version.

## 0.2.0-alpha — 2026-09-04

- Adapted Vault against the supplied AstraeaOS WP 0.1.0-alpha / WordPress 7.1 source tree.
- Added Astraea Core integration bridge for master-key derivation, Argon2id policy, structured logging and GeDefense events.
- Moved autonomous recovery gate to the earliest DB-ready/pre-plugin load point, including Multisite network-plugin protection.
- Added deferred recovery e-mail delivery for ultra-early bootstrap where `wp_mail()` is not yet loaded.
- Added plugin-scoped pre-update snapshots instead of unnecessary database snapshots.
- Added custom `WP_PLUGIN_DIR` safe path handling.
- Bound restore/verification to AVB backup ID and plugin snapshot manifest context.
- Added Vault-passphrase reauthentication to backup download and deletion.
- Added passphrase attempt rate limiting.
- Added automatic pre-restore safety snapshot and rollback attempt after failed manual apply.
- Authenticate Vault passphrase before generating the pre-restore safety backup to prevent I/O-amplification abuse.
- Added dependency consistency review and fixed missing Notifier injection in the restore error path.
- Updated Vault UI to consume Astraea Glass design tokens.
- Added exact AstraeaOS WP core integration patch.

## 0.1.0-alpha

- Initial encrypted AVB container, local storage, backup/restore, scheduler, Update Guard and recovery implementation.
