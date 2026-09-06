# Threat Model & Security Architecture — AstraeaOS WP

<!-- STATUS: DIAMANT VGT SUPREME -->

This document details the threat landscape, attacker models, trust boundaries, and defensive mitigations for **AstraeaOS WP**, structured using the **STRIDE** methodology.

---

## 1. System Scope & Trust Boundaries

AstraeaOS WP establishes strict trust boundaries separating untrusted external inputs from core kernel operations:

```
[ Untrusted World / Web Clients ]
               │
      [ L0 Native Perimeter ]  <-- IP Drops (Pure PHP, zero DB query)
               │
      [ L1/L2 GeDefense WAF ]  <-- Header, Query, Payload Inspection
               │
   ┌───────────┴───────────┐
   ▼                       ▼
[ Frontend Handlers ]  [ Authenticated Admin / API ]
   │                       │
   ▼                       ▼  <-- Step-Up Authentication Boundary (15 min)
[ Core Execution Layer (Astraea Kernel / WP Core) ]
   │
   ▼  <-- Domain-Separated Cryptographic Boundary (AEAD / Argon2id)
[ Storage Layer: MySQL / File System / Secret Store ]
```

---

## 2. Threat Actors & Capabilities

| Threat Actor | Capabilities & Motivation | Primary Vectors |
| :--- | :--- | :--- |
| **External Unauthenticated Attacker** | Network-based adversary with no credentials; opportunistic exploitation. | SQLi, XSS, SSRF, brute force, XML-RPC amplification, polyglot file uploads, ReDoS. |
| **Compromised Third-Party Plugin** | Malicious or vulnerable plugin executing PHP in the same shared process context. | Unsanitized inputs, arbitrary file write, unauthenticated option mutation, remote code execution. |
| **Malicious Authenticated User** | Low-privilege user (Subscriber/Author) attempting horizontal or vertical privilege escalation. | IDOR, privilege escalation, file upload bypass, stored XSS in comments or profiles. |
| **Compromised Admin Session** | Adversary possessing stolen administrator session cookies. | Silent credential theft, destructive snapshot deletion, malicious backdoor injection. |
| **Malicious Network / MITM** | In-path adversary intercepting unencrypted transport. | Downgrade attacks, SMTP credential eavesdropping, cleartext token interception. |

---

## 3. STRIDE Threat Analysis & Mitigations

### 1. Spoofing (Identity & Session Spoofing)
- **Threat**: Attacker spoofs client IP headers (`X-Forwarded-For`, `CF-Connecting-IP`) to bypass IP allowlists or rate limits.
  - *Mitigation*: Astraea trusts only direct TCP connection parameters (`$_SERVER['REMOTE_ADDR']`). Proxy headers are rejected by default in security-critical contexts (Dattrack, Hades, Perimeter).
- **Threat**: Attacker steals administrator cookie and executes privileged operations.
  - *Mitigation*: **PrivilegedActionGuard** and **Step-Up Authentication**. Critical operations (key rotation, vault restore, diagnostics export, module toggles) require re-entering the account password. Elevation is cryptographically bound to the specific WordPress session token fingerprint.

### 2. Tampering (Code & Data Modification)
- **Threat**: Malicious upload of image polyglots containing embedded PHP or web shell payloads.
  - *Mitigation*: **Astraea Media Engine**. Integer-constant MIME checks (`IMAGETYPE_*`), EXIF stripping, memory pre-flight, and GD re-encoding neutralize hidden payloads.
- **Threat**: Upstream WordPress update overwriting Astraea security structures.
  - *Mitigation*: **CoreUpdateGuard** intercepts all core update hooks, filters transients, and suppresses upstream version checks.
- **Threat**: ZipSlip path traversal during update extraction.
  - *Mitigation*: Path jail enforcement asserts `realpath()` resolution and rejects archive entries targeting outside the extraction jail.

### 3. Repudiation (Action Non-Repudiation)
- **Threat**: Administrator denies performing a high-risk mutation; consent changes cannot be audited.
  - *Mitigation*: **SecurityEventBridge** records immutable security audit events; **VLP Light** issues HMAC-authenticated HttpOnly consent receipts; **Vault** logs snapshot fingerprints.

### 4. Information Disclosure (Sensitive Data Leakage)
- **Threat**: Plaintext database compromise exposes SMTP passwords, OAuth tokens, and form submissions.
  - *Mitigation*: **CryptoService AEAD**. Sensitive fields are encrypted at rest with domain-separated keys (`MAIL_TRANSPORT`, `FORMS_SUBMISSION`, `VLP_DATTRACK`).
- **Threat**: Diagnostic support bundle inadvertently leaks API keys, database credentials, or auth salts.
  - *Mitigation*: **DiagnosticsBundle** enforces deep recursive redaction of sensitive key patterns before exporting data.

### 5. Denial of Service (System Availability Attacks)
- **Threat**: Regular expression Denial of Service (ReDoS) in redirect rules.
  - *Mitigation*: **RedirectEngine** enforces strict regex length bounds (<=128 chars), restricts nesting quantifiers, and bounds redirect chain evaluation to a depth of 5.
- **Threat**: Repeated fatal boot crashes bricking the site in an endless loop.
  - *Mitigation*: **BootFailureDetector** tracks in-flight boot attempts; 3 consecutive incomplete boots automatically engage the isolated **Recovery Gate** (`/astraea-recovery/`).

### 6. Elevation of Privilege (Unauthorized Capability Expansion)
- **Threat**: Plugin tries to write directly to protected files or tamper with the master key.
  - *Mitigation*: **FileGuard** monitors upload paths and `.htaccess` integrity; **MasterKeyManager** stores master key material outside webroot with strict `0600` permissions.
