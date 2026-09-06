# AstraeaOS WP 0.3.0-alpha — Security Remediation Ledger

This ledger records the hardening pass performed against the previous alpha. A status of `FIXED` means the shipped source contains the remediation; it is not a third-party certification.

| # | Finding | Status | Remediation |
|---|---|---|---|
| 1 | Passkeys falsely reported READY | FIXED | Passkeys/MFA are reported `NOT IMPLEMENTED` until a real WebAuthn implementation exists. |
| 2 | Step-Up service was not connected to sensitive actions | FIXED | Central privileged-action guard added for interactive plugin/theme install/update, code edit/delete, admin creation/promotion/email changes and security-policy mutation. Vault critical actions are guarded directly. |
| 3 | Step-Up elevation stored per user | FIXED | Elevation is keyed by SHA-256 fingerprint of the exact current WordPress session token and expires after 900 seconds. |
| 4 | Synthetic successful security events | FIXED | Synthetic baseline events removed. Empty event history renders empty. |
| 5 | Security Event Manager not wired | FIXED | GeDefense EventBus and Vault action bridge added; Auth, FileGuard and Integrity emit direct evidence events. |
| 6 | Morpheus source-file presence could report HEALTHY | FIXED | HEALTHY requires policy enabled plus actual runtime/hypervisor class loaded. |
| 7 | FileGuard probe overclaimed web-server protection | FIXED | Upload and MIME hooks are verified independently; Apache execution barrier is asserted only when its actual rules are present. |
| 8 | Aegis mislabeled as header engine | FIXED | Aegis UI/probe now describes DPI. Titan/HTTP policy is a distinct probe. |
| 9 | Duplicate header engines | FIXED | Titan is authoritative when enabled and loaded; Astraea HeaderPolicy becomes fallback and abstains from overlapping emission. |
| 10 | Vault UI used hardcoded recovery/update state | FIXED | UI reads real early-boot, UpdateGuard registration, storage, key-slot and repository verification evidence. |
| 11 | Network-plugin failed rollback quarantine edge case | FIXED | Quarantine removes both per-site and `active_sitewide_plugins` activation state when applicable. |
| 12 | Root manifest stale | FIXED BY BUILD GATE | Manifests are regenerated after runtime pruning and verified against the frozen release before packaging. |
| 13 | Manifest was described as signed but was not | FIXED | Unsigned builds say `UNSIGNED / local integrity only`. Optional Ed25519 offline signing and verification are implemented with libsodium. |
| 14 | Test/Vault documentation described nonexistent classes/behaviors | FIXED | Reports were rewritten from the actual source; unexecuted runtime scenarios are marked NOT TESTED. |
| 15 | Conflicting Astraea versions | FIXED | `Astraea\Version::VERSION` is the single distribution source of truth. Vault module version is centralized in `Vault/version.php`; AVB format version remains independent. |
| 16 | Production archive contained development artifacts | FIXED BY BUILD GATE | Source and runtime packages are separated. Runtime build excludes tools, development reports, patches, GeDefense regression scripts and PDFs. |

## Additional remediation included

- Password-pepper rotation supports external previous-pepper migration without storing peppers in the DB.
- Password hash created without a pepper can migrate after pepper activation.
- Vault schema/storage installation is idempotent and core-native; it no longer depends on plugin activation hooks.
- Vault schema creation is verified before its schema version is persisted.
- Vault core-native initialization failure becomes explicit `CRITICAL` evidence without intentionally crashing the entire site.
- Session revocation operations require session-bound Step-Up and emit sanitized events.
- Release file integrity is separated from release authenticity in both code and UI.
- `wp-settings.php` no longer falls back to a removed `wp-content/plugins/astraea-vault` copy.

## Additional critical lifecycle correction discovered during release verification

- **Keyring reinitialization:** an unknown `keyId` could previously trigger a reinitialization path that discarded in-memory historical keys registered during the current process. Initialization is now idempotent once a ring is established.
- **Durable rotation:** `Keyring::rotateKey()` now refuses to commit a rotation unless the previous ACTIVE key can first be persisted as `DECRYPT_ONLY` to `ASTRAEA_KEYRING_FILE` outside the WordPress webroot. The file is atomically written with `0600` permissions.
- **Durable revocation:** revocation is persisted before in-memory state changes, and a revoked record remains blocked after complete keyring reinitialization.
- **Pepper migration helper:** the previous-pepper helper was corrected to perform HMAC-SHA384 preparation rather than recursively calling itself; release verification exercises the migration path.

## Remaining intentionally unimplemented feature

Native WebAuthn/passkeys are not implemented in this alpha. This is visible in the UI and is not counted as an active security control.
