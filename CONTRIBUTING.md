# Contributing to AstraeaOS WP

<!-- STATUS: DIAMANT VGT SUPREME -->

Thank you for your interest in contributing to **AstraeaOS WP**.

AstraeaOS WP is an independent, security-first WordPress distribution engineered under the **VGT Doktrin (VisionGaia Technology Master Intelligence System v2.1 | Diamant VGT Supreme)**.

Our architectural vision is **"WordPress without the Plugin Stack."** We deliver universal, enterprise-grade infrastructure systems as first-party kernel modules while maintaining standard WordPress plugin/theme interoperability for specialized business logic.

---

## 1. Core Architectural Guidelines

Before submitting code, ensure you understand our core design principles:

1. **Deliberately Minimal Upstream Core Diff**:
   - Changes to files in `wp-admin/`, `wp-includes/`, or root PHP files must be strictly minimal, surgical, and well-documented.
   - All custom capabilities, logic, models, and interfaces belong inside `astraea-core/`.
2. **Zero-External Dependencies**:
   - No runtime Composer packages, no npm build dependencies, no remote CDNs, no Google Fonts.
   - All styling must use the internal Astraea Glassmorphism design system (Cyan / Obsidian tokens).
   - Scripts must be vanilla modern ECMAScript without runtime bundler baggage.
3. **Fail-Closed by Default**:
   - If an extension, key, or dependency is absent or invalid, the system must fail closed and record an opaque, safe error.
   - No silent degradation to insecure defaults (e.g. falling back to unencrypted HTTP or plaintext SMTP).
4. **No Synthetic Claims**:
   - All health probes, status reports, and telemetry must reflect live, measured system evidence. Mock or synthetic "all systems nominal" statuses are strictly forbidden.

---

## 2. The VGT Mandatory Code Pattern Library

All code contributions must strictly adhere to the following six mandatory patterns:

### Pattern 1.5.A — Typed Exception Hierarchy
Never throw generic `\Exception` or `\RuntimeException`. Use domain-specific exceptions extending `Astraea\Exceptions\AppException`:
```php
use Astraea\Exceptions\ValidationException;
use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\StorageException;

if ($signatureInvalid) {
    throw new SecurityException('Cryptographic signature verification failed.');
}
```

### Pattern 1.5.B — Measured Temporary File Size
Never trust client-supplied file size metadata in `$_FILES['size']`. Always validate the actual file size directly on the local temporary file:
```php
$actualSize = filesize($tmpPath);
if ($actualSize === false || $actualSize > $maxAllowedBytes) {
    throw new ValidationException('Upload violates maximum size constraints.');
}
```

### Pattern 1.5.C — Error Handler & Logging Consistency
Never leak internal error details, stack traces, or credentials to client responses:
- `ini_set('display_errors', '0')` in production contexts.
- Record structured security logs through `SecurityEventManager` and `Logger`.

### Pattern 1.5.D — Integer Constant MIME Validation
Never rely on spoofable file extensions or text strings alone for MIME validation. Always cross-check using PHP image type constants:
```php
$info = @getimagesize($tmpPath);
if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_AVIF], true)) {
    throw new SecurityException('Invalid image payload detected.');
}
```

### Pattern 1.5.E — Realpath Post-Construction Path Jail
Never trust relative paths or simple string prefixes for filesystem access:
```php
$resolvedTarget = realpath($targetPath);
$resolvedJail = realpath($jailDirectory);

if ($resolvedTarget === false || !str_starts_with($resolvedTarget, $resolvedJail . DIRECTORY_SEPARATOR)) {
    throw new SecurityException('Path traversal attempt blocked outside jail.');
}
```

### Pattern 1.5.F — DOM Construction & textContent
Never inject dynamic user data via `innerHTML` or string interpolation in JavaScript. Always use safe DOM methods:
```javascript
const el = document.createElement('span');
el.textContent = userData; // Safe
container.appendChild(el);
```

---

## 3. Development Environment & Testing

### Prerequisites
- PHP 8.3+ (64-bit architecture mandatory)
- Required PHP Extensions: `sodium`, `openssl`, `hash`, `json`, `mbstring`, `intl`, `gd`, `zip`, `dom`, `libxml`
- MySQL 8.0+ or MariaDB 10.11+

### Quality Gates & Test Suites
Before submitting any contribution, you must execute and pass the complete test suite:

```bash
# 1. Run Astraea Core Foundation Unit Tests
php tests/run_tests.php

# 2. Run Comprehensive Release & Invariant Checks
php tests/run_release_checks.php

# 3. Regenerate and Verify Release Manifests
php tools/build-release.php --verify-only
```

Every test must pass (100% PASS, 0 failures). Any failure will block PR merge.

---

## 4. Pull Request & Commit Standards

- **Title**: Prefixed with subsystem name (e.g., `Modules: add dependency cycle prevention`, `Forms: enforce IP rate limiter`).
- **Description**: Explain the motivation, affected components, and verified test results.
- **Documentation**: If your change adds or alters configuration, update the corresponding `docs/` or root markdown guide.
- **Licensing**: All code submitted must be compatible with the AstraeaOS WP distribution license (GPLv2+).
