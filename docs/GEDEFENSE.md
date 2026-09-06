# AstraeaOS WP — GeDefense First-Party Security Kernel

**Distribution:** AstraeaOS WP `0.1.0-alpha`  
**Integration Status:** Native First-Party Core Security Module  
**Location:** `/astraea-core/GeDefense/`  

---

## 1. Native Integration vs. Third-Party Plugin

In conventional WordPress installations, security plugins operate late in the execution lifecycle (after `wp-settings.php`, database initialization, and plugin loading), meaning attacks waste significant server CPU and database I/O before being blocked.

In **AstraeaOS WP**, GeDefense is integrated as a **First-Party Security Kernel**:
- Boots during **Phase 1 Pre-Flight** inside `astraea-bootstrap.php` before WordPress plugins or themes load.
- Avoids duplicated security infrastructure by binding directly to `Astraea\Crypto\MasterKeyManager`.
- Eliminates database storage of vault encryption keys.
- Reduces WordPress hook overhead by executing deterministic early inspections.

---

## 2. Boot Order & Defense Pipeline

```
[ Incoming HTTP Request ]
            │
            ▼
[ 1. Runtime Baseline Check ]
            │
            ▼
[ 2. Astraea Cryptographic Engine ]
            │
            ▼
[ 3. GeDefense Phase 1 Pre-Flight Kernel ]
            ├── Cerberus    (L0 Perimeter Drop in < 0.001 ms)
            ├── Zeus        (Pre-Boot 6G WAF & Query Sanitizer)
            ├── AEGIS       (Deep Packet Inspection: SQLi, XSS, RCE)
            ├── Hades       (Route Cloaking & Timing-Safe Gate)
            └── Prometheus  (Subnet Threat Scoring)
            │
            ▼
[ 4. WordPress Database Initialized ($wpdb) ]
            │
            ▼
[ 5. Pluggable Password Overrides (Argon2id Active) ]
            │
            ▼
[ 6. Legacy Surfaces Pruned (XML-RPC & Emojis Disabled) ]
            │
            ▼
[ 7. Active Plugins Loaded ]
            │
            ▼
[ 8. GeDefense Phase 2 Invariants ]
            ├── Titan Browser Confinement (CSP, HSTS, Permissions)
            ├── Morpheus RASP (Runtime Application Self-Protection)
            ├── Airlock Ingress File Streaming
            └── TRINITY XDR Event Fabric
            │
            ▼
[ 9. Request Execution & Dev Profiler Shutdown ]
```

---

## 3. Subsystem Cartography

| Subsystem | Operational Phase | Responsibility |
| :--- | :--- | :--- |
| **Cerberus** | L0 Pre-Boot | O(1) in-memory IP blacklist lookup; drops malicious traffic in < 1 microsecond. |
| **Zeus** | L0/L1 Ingress | Pre-boot 6G query string filtering, bot user-agent filtering. |
| **Aegis** | L2/L3 DPI | Deep Packet Inspection of GET, POST, JSON, and Multi-Part data for SQLi, XSS, RCE, and deserialization. |
| **Hades** | L4 Stealth | Admin cloaking gate; serves realistic 404 responses to unauthorized login probes. |
| **Airlock** | L5 Ingress | Upload validation, polyglot PHP payload detection, and SVG cleaning. |
| **Titan** | L5 Browser | Content Security Policy (CSP), COOP/CORP headers, and browser confinement. |
| **Morpheus** | L6 RASP | Callstack analysis, isolating DML operations on `wp_users`, blocking SSRF to cloud metadata IPs (`169.254.169.254`). |
| **Key Vault** | Core Foundation | Libsodium / AES-256-GCM authenticated encryption bound to `Astraea\Crypto\MasterKeyManager`. |
| **TRINITY XDR** | Automation | Normalized event fabric recording structured attack stories, forensic evidence, and incidents. |
