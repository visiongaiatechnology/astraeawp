# Cryptography

## Algorithms

- Payload AEAD: AES-256-GCM
- Authentication tag: 128 bit
- GCM nonce: 96 bit
- Password KDF: Argon2id13 via libsodium
- Domain-separated derivation: HKDF-SHA-256
- File/integrity digest: SHA-256
- Randomness: `random_bytes()`

## Envelope encryption

The Vault passphrase is never used directly as an AES key.

Initialization generates a random 256-bit Vault Master Key. The master key is wrapped into independent key slots:

1. **Password slot** — Argon2id-derived KEK.
2. **Service slot** — unattended local/Astraea service key.
3. **Recovery slot** — key derived from the one-time offline recovery secret.

Every backup generates a new random 256-bit Backup Data Key. The BDK is wrapped with the Vault Master Key and stored in the AVB header.

## Astraea service-key priority

1. Explicit `ASTRAEA_VAULT_SERVICE_KEY` constant/environment secret.
2. Domain-separated derivative of AstraeaOS `MasterKeyManager` when available.
3. Derivative of sufficiently populated WordPress secret constants.
4. Local private key file only when Vault storage is outside the public application root.

## AVB nonce construction

Each backup receives a fresh random 64-bit nonce prefix. Each encrypted record appends a monotonically increasing unsigned 32-bit counter:

`random_prefix[64] || counter[32]`

The BDK is unique per backup. Counter exhaustion aborts the backup before nonce reuse can occur.

## AAD

Each record binds:

`AVB1 | backup_id | record_type | record_counter`

This makes record movement/reordering detectable in addition to the explicit sequence counter validation.

## Header authentication

The public AVB header contains the wrapped BDK, portable key slots and format metadata. Its exact serialized representation is SHA-256 hashed and that digest is stored as the first encrypted authenticated record. Modifying the header therefore causes verification failure.

## Compression

Compression occurs before encryption and only when it actually reduces a record. Decompression has a hard output ceiling.

## Secret-memory limitation

PHP is not a memory-safe secret-processing runtime. Vault uses `sodium_memzero()` where practical, but cannot guarantee removal of copies created by PHP/OpenSSL/runtime internals. This is documented rather than hidden behind an unrealistic claim.
