# Security Policy — AstraeaOS WP

AstraeaOS WP is a security-first WordPress distribution, but **no release is claimed to be unhackable, formally verified or externally certified unless a specific independent certification is explicitly published**. Security controls are implemented in layers and are subject to continued review.

## Supported stream

During the public alpha period, only the **current alpha release line** receives active project security fixes. Users should update to the newest available alpha before reporting an issue unless reproduction specifically requires an older build.

## Reporting a vulnerability

Do **not** disclose a suspected security vulnerability in a normal public GitHub issue before coordinated triage.

Preferred channels:

1. Use **GitHub Private Vulnerability Reporting** if enabled for this repository.
2. Otherwise use the official security/contact channel published by VisionGaiaTechnology for AstraeaOS.

A useful report includes the affected component, prerequisites, reproducible steps or PoC, expected/observed behavior, impact assessment and any suggested remediation.

## Security design highlights

- Sensitive reversible records use authenticated encryption through the centralized Astraea crypto/keyring fabric.
- Passwords use one-way Argon2id hashing rather than reversible encryption.
- High-impact mutations can require session-bound Step-Up authentication.
- Recovery and Vault logic are positioned before normal plugin loading where the boot architecture requires it.
- Release integrity uses SHA-256 manifests; update authenticity requires a valid Ed25519 signature.
- File operations are designed around bounded processing and path-jail validation.
- Security event status is evidence-based; unknown/unverified states should not be rendered as successful checks.

## Alpha limitations

The alpha series is under active compatibility and adversarial testing. Security reviews and automated tests reduce risk but do not prove absence of vulnerabilities. Operators are expected to keep independent backups, validate restores and test upgrades in staging.

## Upstream security

AstraeaOS WP tracks WordPress as its upstream base. Relevant upstream security fixes must be reviewed and integrated into the Astraea tree rather than applied through an unverified automatic WordPress core overwrite.
