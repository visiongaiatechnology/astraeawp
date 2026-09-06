# Update Security Architecture — AstraeaOS WP

<!-- STATUS: DIAMANT VGT SUPREME -->

This document describes the cryptographic principles, transactional protocol, and security barriers governing the **Astraea Update Engine** (`astraea-core/Update/`).

AstraeaOS WP treats operating system and distribution updates as a critical security boundary. Updates are executed through a transactional 9-step state machine engineered to eliminate supply chain tampering, downgrade attacks, ZipSlip path traversals, and mid-update corruption.

---

## 1. Upstream WordPress Overwrite Shield (`CoreUpdateGuard`)

AstraeaOS WP is an independent distribution fork. If an upstream WordPress update were accidentally applied over AstraeaOS WP, it would overwrite core-native security structures, wipe out the cryptographic keyring, and destroy the first-party module layer.

To prevent this, `CoreUpdateGuard` enforces an uncompromising shield:
- **Transient Purging**: Intercepts `pre_set_site_transient_update_core` and `site_transient_update_core` with maximum priority (9999) to clear any offered upstream core updates.
- **Auto-Update Suppression**: Returns `false` on `auto_update_core`, `allow_major_auto_core_updates`, `allow_minor_auto_core_updates`, and `allow_dev_auto_core_updates`.
- **Network Interception**: Filters `pre_http_request` to block outgoing version-check requests directed at `api.wordpress.org/core/version-check/`.

All core updates must arrive exclusively through the verified Astraea Release Engine.

---

## 2. Cryptographic Release Signing & Verification

### A. Ed25519 Detached Signatures
Every AstraeaOS WP release is cryptographically signed using **Ed25519** (Edwards-curve Digital Signature Algorithm). The private signing key is stored in offline, air-gapped security modules and is never present on production servers.

### B. Canonical Manifest Payload
To ensure deterministic verification, the detached signature signs a canonicalized manifest payload:
```
ASTRAEA-RELEASE-V1
project=AstraeaOS WP
version=0.6.0-alpha
corpus_sha256=<64-char-hex-hash>
total_files=<integer>
```

### C. Anti-Downgrade Protection
The `UpdateVerifier` strictly checks version semantics (`version_compare`). Any attempt to apply an update with a version number equal to or lower than the currently running version is immediately rejected as a potential replay or downgrade attack.

---

## 3. Transactional 9-Step Update Protocol

The update procedure executes through nine atomic stages:

```
[1. Manifest Check] -> [2. Package SHA-256] -> [3. Vault Snapshot]
        │
        ▼
[4. Staging Extract] -> [5. ZipSlip Check] -> [6. Payload Verification]
        │
        ▼
[7. Atomic Swap]     -> [8. Health Probes]    -> [9. Commit / Rollback]
```

1. **Manifest Retrieval & Authenticity Probe**:
   - The release manifest is fetched over TLS and its Ed25519 signature is verified against the pinned Astraea root public key.
2. **Package Hash Verification**:
   - The release archive is downloaded into a temporary staging area; its SHA-256 checksum is computed and matched against the signed manifest.
3. **Pre-Update Immutable Snapshot**:
   - **Astraea Vault** creates an unalterable `.avb` snapshot of all existing core files and the current database state. If any subsequent step fails, this snapshot serves as the rollback target.
4. **Isolated Path-Jailed Extraction**:
   - The archive is extracted into an isolated directory outside the live webroot.
5. **ZipSlip & Symlink Immunity**:
   - Every file path in the archive is sanitized. Entries containing directory traversal sequences (`../`), absolute paths, or symbolic links targeting outside the extraction jail are rejected with a `SecurityException`.
6. **Extracted Payload Integrity Check**:
   - Every extracted file is hashed with SHA-256 and matched against the file list in the manifest before any live files are touched.
7. **Atomic Filesystem Swap**:
   - Files are swapped atomically using filesystem renames (`rename()`), minimizing live downtime to sub-millisecond windows.
8. **Post-Update Automated Health Probes**:
   - Astraea immediately executes health probes across all core modules (`ModuleRegistry::probeHealth()`).
9. **Commit or Automatic Rollback**:
   - If all health probes pass, the update transaction is marked committed.
   - If any probe fails, the system triggers immediate rollback to the Vault snapshot created in Step 3.
