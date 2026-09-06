# GitHub Public Alpha Packaging Report

**Target:** AstraeaOS WP `0.6.0-alpha` — First Public Alpha prerelease  
**Packaging scope:** release hygiene, licensing/branding, manifest rebuild and archive verification. No functional kernel changes were introduced by this packaging pass.

## Release hygiene

- Removed accidental `powershell.exe` and `powershell.cmd` artifacts.
- Removed obsolete `0.4.0-alpha` build report and stale `0.6.0-alpha` root build report.
- Removed generated boot-state data from `wp-content/uploads/vgt-temp/`.
- Replaced legacy upstream WordPress HTML readmes with AstraeaOS-specific offline readmes.
- Retained the original WordPress `license.txt` unchanged.
- Added explicit AGPLv3 / WordPress GPLv2-or-later component-scoped licensing documentation.
- Added trademark, notice and third-party notice documents.
- Added GitHub issue and pull-request templates.
- Added the AstraeaOS WP handbook valid for the alpha series under `docs/`.

## Verification performed in this packaging environment

- `php tests/run_release_checks.php`: **98 passed / 0 failed**.
- PHP syntax validation: **1993 / 1993 files passed**.
- JavaScript syntax validation: **662 / 662 files passed**.
- Secret hygiene scan: no PEM/OpenSSH private-key headers found in the source tree.
- Release hygiene assertions: no PowerShell binary/script artifact and no generated upload-state file remains in the clean source tree.

## Full integration-suite limitation of this packaging environment

`php tests/run_tests.php` was started but the local packaging runtime does not satisfy AstraeaOS WP's declared server baseline. The environment lacks `mbstring`, `intl`, `dom`/`DOMDocument`, and a MySQL driver. The suite therefore reaches `PublicReleaseTest` and stops when the SVG sanitizer requires `DOMDocument`.

This is recorded as **NOT EXECUTED TO COMPLETION IN THIS PACKAGING ENVIRONMENT**, not as a failed Astraea product assertion. Secure Genesis and `RuntimeCheck` correctly declare DOM/XML and the other required extensions as mandatory.

Packaging-environment baseline:

```text
PHP 8.4.23 64-bit      PASS
sodium                  PASS
OpenSSL                  PASS
Argon2id                 PASS
json/hash                PASS
mbstring                 MISSING
intl                     MISSING
DOM/XML                  MISSING
mysqli/pdo_mysql         MISSING
```

## Authenticity

The final manifests produced by this packaging pass are intentionally **UNSIGNED** because no offline VGT Ed25519 release signing key was supplied. SHA-256 integrity is still generated and verified.

This artifact is therefore suitable as a **manual GitHub public-alpha prerelease**, but it must not be represented as an authenticated VGT updater release. The Astraea Update Engine is expected to reject unsigned update packages.

## Licensing

- AstraeaOS-specific original code and assets: **GNU AGPL v3**.
- WordPress upstream and WordPress-derived portions: **GNU GPL v2 or later**.
- Bundled third-party components retain their own notices/licenses.

## Trademark

**AstraeaOS™ is a trademark of VisionGaiaTechnology.** This release does not claim that the mark is registered.
