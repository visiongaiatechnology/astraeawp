# Astraea Vault

**STATUS: DIAMANT VGT SUPREME (VGT-internal engineering classification)**  
**Version:** 0.3.0-alpha  
**Target:** AstraeaOS WP 0.3.0-alpha / WordPress 7.1  
**License:** AGPL-3.0-or-later

Astraea Vault is the first-party encrypted backup, update-continuity and autonomous plugin-recovery engine for AstraeaOS WP.

It is intentionally not designed as a generic archive plugin. The core model is:

`Snapshot → Verify → Update → Observe → Commit / Roll back`

## Implemented security model

- AES-256-GCM authenticated encryption.
- A fresh random 256-bit Backup Data Key (BDK) for every backup.
- Envelope encryption: BDK → Vault Master Key → password/service/recovery key slots.
- Argon2id passphrase KDF through libsodium.
- AstraeaOS `Argon2idPolicy` integration when available.
- AstraeaOS master-key-derived unattended service slot when available.
- No Vault passphrase persistence.
- One-time offline recovery key at initialization.
- Chunked authenticated container format (`.avb`) with sequence-bound AAD.
- Encrypted final manifest and explicit truncation/trailing-data detection.
- 0700 storage/staging directories and 0600 Vault artifacts.
- Restore path jails and traversal rejection.
- Restore to private staging before applying live state.
- Database backup under a repeatable-read consistent snapshot.
- Backup verification before status becomes `verified`.
- Per-user Vault-passphrase rate limiting.
- Passphrase reauthentication for restore, download and delete.
- No CDN or external runtime dependency.
- No VGT telemetry.

## Continuity / update guard

For single-plugin updates Vault creates a **plugin-scoped encrypted snapshot**, verifies it, then allows the update. The snapshot does not include the full database by design; this avoids destructive DB rollback for a plugin failure.

After the update, Vault records an update transaction. A fatal error is eligible for autonomous rollback only when it correlates to the updated plugin path inside a bounded post-update window. If rollback cannot be completed, Vault quarantines/deactivates the plugin and records an incident.

A failed version is blocked from repeating as an automatic update until a newer version is available.

## AstraeaOS WP core integration

Astraea Vault is shipped **core-native** at `astraea-core/Vault/`. There is no duplicate `wp-content/plugins/astraea-vault/` runtime copy and no plugin activation step.

The AstraeaOS boot orchestrator loads Vault in Phase D after WordPress database/options/multisite initialization and **before must-use, network-active and normal plugins are loaded**. This allows pending plugin recovery to execute before a broken plugin is included again.

Production deployments should prefer an explicit server-side `ASTRAEA_MASTER_KEY` and a Vault storage path outside the application web root via `ASTRAEA_VAULT_STORAGE_PATH`.

## Key hierarchy

```text
Vault passphrase
    │
    └─ Argon2id → Password KEK ─┐
                                │
Astraea master / service source ├─> wrapped Vault Master Key
                                │
Offline recovery secret → HKDF ─┘

Vault Master Key
    │
    └─ AES-256-GCM wraps one random BDK per backup

Backup Data Key
    │
    └─ AES-256-GCM authenticates/encrypts every AVB record
```

Changing the Vault passphrase only re-wraps the Vault Master Key. Existing backup payloads do not need to be re-encrypted.

## Backup types

- `full`: database + files beneath `ABSPATH`, excluding Vault storage and known caches.
- `database`: database only.
- `plugin`: plugin-scoped file snapshot used by Update Guard.

## Recovery behavior

### Manual restore

1. Check capability + nonce + POST method.
2. Rate-limit and authenticate Vault passphrase.
3. Create and verify a fresh full pre-restore safety snapshot.
4. Decrypt the requested backup into private staging.
5. Validate record sequence, AEAD tags, manifest, file sizes and SHA-256 hashes.
6. Apply selected database/files scope.
7. If the apply phase fails, attempt automatic restoration of the pre-restore safety snapshot.

### Automatic plugin rollback

1. Update Guard creates and verifies plugin snapshot.
2. Plugin update executes.
3. Fatal monitor correlates eligible fatal error to the exact plugin path.
4. Recovery marker is stored.
5. On the next bootstrap, Astraea's pre-plugin Vault gate runs.
6. Current failed plugin is quarantined.
7. Verified previous plugin files are restored.
8. On restore failure, the plugin is kept quarantined/deactivated.
9. Admin notice is persisted; e-mail is sent or deferred until `wp_mail()` is available.

## Astraea native integrations

When running inside the supplied AstraeaOS WP fork, Vault automatically uses:

- `Astraea\Crypto\MasterKeyManager`
- `Astraea\Auth\Argon2idPolicy`
- `Astraea\Security\Logger`
- `VisionGaia\GeDefense\Core\EventBus`
- Astraea Glass design tokens

Vault remains modular so it can be loaded as a plugin during development and later moved into the first-party core tree.

## Runtime requirements

- PHP 8.3+
- OpenSSL with `aes-256-gcm`
- Sodium
- WordPress 6.8+ API baseline
- Designed and source-integrated against AstraeaOS WP / WordPress 7.1

## Test status for 0.3.0-alpha

Passed in the build environment:

- PHP syntax lint over every PHP file.
- JavaScript syntax check.
- AVB crypto round-trip smoke test.
- AVB tamper-detection smoke test.
- Exact dry-run + application of the AstraeaOS WP `wp-settings.php` patch.
- Static scans for TODO/FIXME placeholders, external CDN references and unsafe JS sinks.
- Dependency-property consistency scan for PHP classes.

Not claimed by this alpha build:

- full browser-driven end-to-end test against a live MySQL-backed AstraeaOS WP site;
- cross-hosting-matrix validation;
- external cryptographic/security certification.

The VGT status label is an internal engineering classification, not an independent certification.

## Documentation

- `docs/ARCHITECTURE.md`
- `docs/CRYPTOGRAPHY.md`
- `docs/RECOVERY.md`
- `docs/THREAT_MODEL.md`
- `docs/ASTRAEA_INTEGRATION.md`
- `CHANGELOG.md`
