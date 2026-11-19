# AstraeaOS WP 0.8.0 — Pure AstraeaOS Line (WordPress 7.1.5)

**Release type:** Alpha (source distribution)
**Distribution version:** `0.8.0`
**Upstream base:** WordPress `7.1.5-de_DE`

## What this release is

This is the first release of the **pure AstraeaOS WP line**, split off from the
`AstraeaOS_SonaMedia_7.1.4` build. The SonaMedia project skin has been removed
from the distribution; AstraeaOS WP now ships only its own first-party kernel on
top of a clean upstream WordPress core.

Removed in this split (kept in the separate SonaMedia build, not part of AstraeaOS WP):

- `astraea-core/SonaMediaVod/` (native VoD streaming kernel)
- `astraea-core/Modules/CoreModules/SonaMediaVodModule.php` and its `ModuleBootstrap` registration
- `wp-content/plugins/Mediathek/` (VoD/Mediathek plugin)
- `wp-content/themes/MedianetTheme/` (Sonamedia theme)
- `wp-content/mu-plugins/sonamedia-umbau.php` (maintenance gate MU-plugin)
- `docs/sonamedia-nginx-waf-hardened.conf` and the Sonamedia report documents

## Upstream rebase

- WordPress core upgraded `7.1.4` → `7.1.5` (de_DE).
- Astraea lifecycle hooks, Secure Genesis installer and environment hardening were
  re-applied on the 7.1.5 core. Upstream changes in this step were limited to
  `$wp_version` plus regenerated block/editor/REST assets, so the surgical hook
  points carried over unchanged.
- `wp-includes/default-constants.php` is **no longer patched**: `WP_DEFAULT_THEME`
  is back to the upstream default. The only remaining core file modifications are
  `wp-settings.php`, `wp-includes/version.php` and the Secure Genesis installer
  surfaces (`wp-admin/install.php`, `wp-admin/setup-config.php`) plus branded
  `readme.html` / `liesmich.html`.

## GeDefense integrity

- The embedded GeDefense `8.6.0-astraea` kernel is wired twice: deterministically in the Astraea boot sequence (Phase A pre-flight registration, Phase B `engageEarly()` / `engageFull()`) and as the non-toggleable `gedefense` module in `BootPhase::PHASE_C`.
- An inherited bookkeeping defect in the pinned trust anchor was repaired: the manifest listed `gorgon` with 7 files while the tree ships 6, so the module self-check reported `27/28`. `gorgon` was proven byte-identical to the authoritative open-core source (`GeDefense-WP-8.6.0-Open-Core\gedefensewp-main`); the manifest entry was corrected and `VIS_MANIFEST_DIGEST` re-pinned (`9c3403ed…`). The module now verifies **28/28**.
- `tests/run_release_checks.php` fails if the kernel digest, the shipped manifest and the component tree ever disagree again.

## Distribution identity

- `Astraea\Version::VERSION = '0.8.0'`, new `Astraea\Version::WP_BASE = '7.1.5'`.
- GeDefense embedded core stays at `8.6.0-astraea`; `VIS_PRODUCT_NAME` no longer
  carries the SonaMedia branding.

## Verification

Run from the distribution root:

```
php tools/build-release.php
php tests/run_release_checks.php
php tests/run_tests.php
```

`tests/run_release_checks.php` now contains explicit **split guards** that fail if
SonaMedia artefacts (VoD kernel, Mediathek plugin, MedianetTheme, MU-plugin) are
re-introduced into the AstraeaOS WP tree.

## Alpha warning

This is **alpha software**. Use it on staging/lab environments first. Security
audits and regression tests reduce risk but do not constitute a certification or
proof of absence of vulnerabilities. Keep independent backups and validate
restores before relying on the system.

## Authenticity status

If the included `BUILD-MANIFEST.json` reports `UNSIGNED`, the files have local
SHA-256 integrity metadata but **no VGT offline Ed25519 release authenticity
signature**. Astraea's authenticated Update Engine is expected to reject unsigned
update packages. Do not relabel an unsigned artifact as a signed/verified Astraea
release.

## License

- AstraeaOS-specific original work: **GNU AGPL v3**.
- WordPress upstream/derived portions: **GPL v2 or later**.
- Bundled third-party components retain their respective licenses.

See `LICENSE`, `license.txt`, `LICENSES/README.md` and `NOTICE.md`.

## Trademark

**AstraeaOS™ is a trademark of VisionGaiaTechnology.** No registration of the mark is claimed by this release.

## Previous releases

See `CHANGELOG.md` for the full history (latest prior public alpha: `0.6.0-alpha`).
