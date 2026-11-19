# Public Alpha Release Checklist

Release line: **AstraeaOS WP `0.8.0`** on WordPress `7.1.5-de_DE`.

## Repository hygiene

- [x] Replaced upstream WordPress HTML readmes with AstraeaOS-specific offline readmes while retaining upstream `license.txt`.
- [x] Added AGPLv3 license text and component-scoped licensing overview (`LICENSE`, `LICENSES/`).
- [x] Added trademark, notice and third-party notice documents, including data-set provenance (GeoIP CC BY-SA 4.0, jsvectormap MIT).
- [x] Added repository `.gitignore` (secrets, runtime state, build output, internal notes) and `.gitattributes` (`* -text`, so SHA-256 manifests stay byte-exact).
- [x] Removed internal working material from the published tree: agent hand-off notes, lab environment reports and point-in-time internal QA/packaging reports.
- [x] No `wp-config.php`, key material, session data or runtime state in the tree.

## Quality gates (actually executed)

- [x] PHP syntax validation across the whole tree.
- [x] `php tests/run_tests.php` — 698 assertions, 0 failures.
- [x] `php tests/run_release_checks.php` — 113 passed, 0 failed (incl. SonaMedia split guards and the GeDefense trust-anchor guard).
- [x] GeDefense regressions: `audit-fixes-regression.php`, `hardening-regression.php`, `integration-regression.php` — all PASS.
- [x] GeDefense module integrity `VIS_Module_Integrity::verify_all()` — 28/28 VERIFIED.
- [x] `php tools/build-release.php` — manifests generated and verified.
- [x] `php tools/build-release.php --verify-only` — exit 0.

## Release artifacts

- [x] Package source/runtime archives and compute SHA-256 (`php tools/package-distribution.php`).
- [ ] Attach source/runtime archives + `SHA256SUMS` to a GitHub **prerelease**.
- [ ] For authenticated updater distribution, sign with the real offline VGT Ed25519 release key (`--signing-key=…`) and re-verify.

## Known limitations of this release

- The shipped `BUILD-MANIFEST.json` is `UNSIGNED` (local SHA-256 integrity only, no Ed25519 authenticity signature).
- Alpha status: see `SECURITY.md` and the alpha warning at the top of `README.md`.
