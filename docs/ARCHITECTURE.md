# AstraeaOS WP — System Architecture Specification

**Distribution:** AstraeaOS WP `0.1.0-alpha`  
**Base:** WordPress Core 7.1 de_DE  
**Ecosystem:** AstraeaOS / VisionGaiaTechnology  
**Vision:** A modern, hardened, and lightweight WordPress distribution without decades of unnecessary legacy baggage.

---

## 1. System Overview

AstraeaOS WP is an enterprise fork of the WordPress core engineered to deliver modern performance, zero-trust security defaults, and cryptographic integrity.

Rather than patching WordPress haphazardly, AstraeaOS establishes a distinct, namespaced first-party core layer in `/astraea-core/`:

```
wordpress/
├── astraea-core/                 # First-party AstraeaOS Core Layer
│   ├── astraea-bootstrap.php     # Master ignition entrypoint
│   ├── Bootstrap/                # Runtime baseline assertion & PSR-4 autoloader
│   ├── Crypto/                   # Central AEAD service, HKDF KDF, Secure Options
│   ├── Auth/                     # Argon2id password service, policy, and legacy migration
│   ├── Database/                 # Parameterized connection, migration runner, schema upgrades
│   ├── Options/                  # Autoload budget guard and transient hygiene
│   ├── Security/                 # Cookies, CSPRNG randomness, FileGuard, security headers, logging
│   ├── GeDefense/                # Native security kernel (Cerberus, Zeus, Aegis, Hades, XDR)
│   ├── Performance/              # Legacy pruner and dev-mode request profiler
│   └── Diagnostics/              # System health reporting
├── wp-admin/                     # Standard administration interface
├── wp-content/                   # User content (themes, plugins, uploads)
├── wp-includes/                  # Core WordPress libraries (hardened and modern-linked)
├── docs/                         # Technical documentation suite
└── tests/                        # Automated regression and unit test suite
```

---

## 2. Core Namespace Architecture

AstraeaOS core components adhere to PSR-4 namespace standards:

| Namespace | Responsibility |
| :--- | :--- |
| `Astraea\Bootstrap` | Validates host requirements (PHP 8.3+, 64-bit, extensions) and loads classes. |
| `Astraea\Crypto` | Provides Libsodium XChaCha20-Poly1305 AEAD, HKDF context separation, and Secure Options. |
| `Astraea\Auth` | Manages Argon2id hashing, legacy password verification, and automatic login migration. |
| `Astraea\Database` | Wraps database queries with prepared statements, ACID transactions, and versioned migrations. |
| `Astraea\Options` | Enforces autoload byte budgets and eliminates transient accumulation. |
| `Astraea\Security` | Hardens cookies, validates file uploads via magic bytes, injects security headers. |
| `Astraea\GeDefense` | Native Layer 0 / Layer 1 perimeter firewall, WAF, RASP, and threat scoring. |
| `Astraea\Performance`| Prunes legacy bloat (XML-RPC, Pingbacks, Emojis) and profiles requests. |
| `Astraea\Diagnostics`| Aggregates comprehensive system health metrics and security status. |

---

## 3. Request Lifecycle & Ignition Protocol

The AstraeaOS boot sequence executes in strictly defined phases:

1. **Phase 0: Environment & Core Constants (`wp-settings.php`)**
   - Base paths defined.
   - Core version constants defined (`ASTRAEA_VERSION = '0.1.0-alpha'`).
2. **Phase 1: Astraea Bootstrap (`astraea-bootstrap.php`)**
   - PSR-4 Autoloader mounted.
   - Runtime baseline asserted: PHP >= 8.3, 64-bit, sodium, openssl, mbstring, intl, DOM/XML, json.
   - Cryptographic master key initialized; HKDF domain subkeys cached.
   - Pluggable password functions registered before `wp-includes/pluggable.php`.
   - Security baseline and file upload guard filters mounted.
   - Legacy pruner active (XML-RPC and Emojis disabled).
3. **Phase 2: GeDefense Pre-Flight Kernel**
   - Cerberus L0 perimeter checks incoming IP against in-memory blacklist (< 0.001 ms).
   - Zeus 6G WAF sanitizes request URI and query string.
   - Aegis DPI inspects payload for SQLi, XSS, and RCE.
   - Hades validates admin gate access.
4. **Phase 3: Database & Migration Engine**
   - WordPress `$wpdb` initialized.
   - Modern `Connection` layer available.
   - `MigrationRunner` applies pending schema changes idempotently.
5. **Phase 4: WordPress Core & Active Plugins**
   - Core files loaded; active plugins initialized.
   - GeDefense Phase 2 invariants engaged (Titan CSP, Morpheus RASP, XDR fabric).
6. **Phase 5: Request Execution & Dev Profiler**
   - Theme and route executed.
   - In development mode, `RequestProfiler` records execution time, peak memory, and query metrics on shutdown.
