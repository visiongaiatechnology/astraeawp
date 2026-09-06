# Astraea Vault Architecture

## Module boundaries

```text
Admin       UI/controllers only
Backup      source collection, DB streaming, AVB writer/verifier
Crypto      AES-GCM, HKDF, passphrase/service/master-key hierarchy
Integration AstraeaOS Core + GeDefense bridge
Notification persistent notices and deferred mail
Recovery    fatal correlation and autonomous recovery
Repository  backup/incident persistence
Restore     staged validation and controlled apply
Scheduler   recurring backup and retention policy
Security    passphrase abuse controls
Storage     private local Vault filesystem and path jails
Update      transactional plugin Update Guard
Support     locks, UUIDs, PHP error guard
```

No backup payload cryptography is performed in the UI or repository layers.

## Persistence

Two Vault metadata tables are used:

- `{prefix}astraea_vault_backups`
- `{prefix}astraea_vault_incidents`

The keyring and small policy/transaction markers are stored as WordPress options. The keyring only contains wrapped key material, salts, public KDF parameters and metadata; it never stores the Vault passphrase or plaintext Vault Master Key.

## Backup lifecycle

```text
queued/requested
  → DB/files source stream
  → AVB record encoder
  → optional record compression
  → AES-256-GCM
  → .part
  → final authenticated manifest
  → atomic rename to .avb
  → full container verification
  → verified metadata state
```

A failed backup removes the partial artifact and is never promoted to verified.

## Restore lifecycle

```text
AVB
 → authenticated decode
 → private staging
 → file hash/size validation
 → DB staging validation
 → type/scope binding
 → controlled apply
```

Manual restore additionally creates a full pre-restore safety backup after Vault-passphrase authentication and before applying the requested backup.

## Concurrency

Backup and restore use DB-backed named locks so conflicting operations fail closed rather than race. Restore staging is randomly named and private.

## WordPress compatibility

Vault uses normal WordPress capabilities, admin-post actions, cron APIs and upgrader hooks while keeping autonomous recovery in a tiny isolated Astraea core patch. That patch is deliberately separated from the plugin implementation so upstream WordPress merges remain reviewable.
