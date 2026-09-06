# Release Engineering & Build Process — AstraeaOS WP

<!-- STATUS: DIAMANT VGT SUPREME -->

This document governs the release lifecycle, build automation, cryptographic signing, and verification gates for **AstraeaOS WP**.

AstraeaOS WP follows a manifest-driven release philosophy: every published artifact must have a reproducible file corpus, explicit integrity metadata, documented verification status, and quality gates appropriate to its release channel. Bit-for-bit reproducible ZIP output is not claimed unless a specific build proves it.

---

## 1. Release Channels

AstraeaOS WP releases are distributed across four strictly governed channels:

| Channel | Identifier | Stability Profile | Target Audience |
| :--- | :--- | :--- | :--- |
| **Stable** | `stable` | Production-ready, fully verified, signed by air-gapped root Ed25519 key. | Production sites, enterprise deployments. |
| **Beta** | `beta` | Feature complete; subject to broader integration testing. | Staging environments, plugin compatibility labs. |
| **Alpha** | `alpha` | Active development milestone (e.g. `0.6.0-alpha`). | Core contributors, security researchers. |
| **Development** | `development`| Nightly builds; continuously integrated. | Automated CI/CD test runners. |

---

## 2. Deterministic Build Pipeline (`tools/build-release.php`)

The build pipeline is completely autonomous and requires zero external build dependencies:

```
[Source Tree] ──> [Scan & SHA-256 Hash] ──> [Build Core Manifest]
                                                  │
[Offline Ed25519 Key] ──> [Canonical Sign] ──> [Build Root Manifest]
                                                  │
                                           [Post-Build Verification]
```

### Build Commands
```bash
# Standard local build (generates UNSIGNED manifest for development/testing)
php tools/build-release.php

# Release build with offline Ed25519 private signing key
php tools/build-release.php --signing-key=/secure/airgap/ed25519.key

# Verify existing release manifests without rebuilding
php tools/build-release.php --verify-only
```

### Manifest Specifications
1. **Core Manifest (`astraea-core/BUILD-MANIFEST.json`)**:
   - Contains SHA-256 hashes of every file inside `astraea-core/`.
   - Computes deterministic `root_hash` combining path and file hash.
2. **Root Manifest (`BUILD-MANIFEST.json`)**:
   - Contains SHA-256 hashes and byte lengths of every file across the entire distribution.
   - Embeds `corpus_sha256` and detached Ed25519 signature in canonical payload format.

---

## 3. Mandatory Pre-Release Quality Gates

Authenticated updater releases must pass all mandatory quality gates with zero failures. A manually distributed **alpha GitHub prerelease** may be published without the offline Ed25519 signature only when it is explicitly marked `UNSIGNED`, is not offered through the authenticated Astraea Update Engine, and all other available integrity/test evidence is reported without exaggeration:

### Gate 1: Core Foundation Unit Tests
```bash
php tests/run_tests.php
# Requirement: 281+ assertions, 0 failures.
```

### Gate 2: Invariant & Architectural Release Checks
```bash
php tests/run_release_checks.php
# Requirement: 98+ invariant checks, 0 failures.
```

### Gate 3: Cryptographic Manifest Verification
```bash
php tools/build-release.php --verify-only
# Requirement: Exit code 0, all files verified against canonical hashes.
```

---

## 4. Distribution Packaging

Release packaging produces two standardized distribution archives:

1. **AstraeaOS Runtime ZIP (`astraea-wp-runtime-<version>.zip`)**:
   - Contains ready-to-run distribution files (`astraea-core/`, `wp-admin/`, `wp-includes/`, default themes, root entrypoints, and verified manifests).
   - Excludes `.git`, local test scratchpads, and development tooling.
2. **AstraeaOS Source ZIP (`astraea-wp-source-<version>.zip`)**:
   - Contains full repository including test suites, tools, and documentation.
