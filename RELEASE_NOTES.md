# AstraeaOS WP 0.6.0-alpha — First Public Alpha

**Release type:** GitHub prerelease / public alpha

AstraeaOS WP is an independent WordPress distribution built around a coordinated first-party kernel. This is the first release intended for public developer/security-researcher testing.

## Highlights

- Secure Genesis installer with evidence-based environment checks and ThroneGuard initialization.
- GeDefense security kernel and malware triage.
- Astraea Vault encrypted snapshot/recovery engine.
- VLP Light consent/privacy kernel with Dattrack Light.
- Astraea Mail Gateway with strict SMTP/TLS and XOAUTH2 support.
- Module Fabric for first-party subsystem dependency/health management.
- Performance, Media, Redirects, SEO Essentials, Forms Light, Task Center, Database Maintenance, Maintenance Mode, Identity, Compatibility and Migration modules.
- Autonomous recovery environment and guarded update architecture.
- Local Glassmorphism admin/installer experience with no external UI CDN dependency.

## Alpha warning

This is **alpha software**. Use it on staging/lab environments first. Security audits and regression tests reduce risk but do not constitute a certification or proof of absence of vulnerabilities. Keep independent backups and validate restores before relying on the system.

## Authenticity status

This GitHub prerelease is packaged for **manual alpha testing**. If the included `BUILD-MANIFEST.json` reports `UNSIGNED`, the files have local SHA-256 integrity metadata but **no VGT offline Ed25519 release authenticity signature**. Astraea's authenticated Update Engine is expected to reject unsigned update packages.

Do not relabel an unsigned artifact as a signed/verified Astraea release.

## License

- AstraeaOS-specific original work: **GNU AGPL v3**.
- WordPress upstream/derived portions: **GPL v2 or later**.
- Bundled third-party components retain their respective licenses.

See `LICENSE`, `license.txt`, `LICENSES/README.md` and `NOTICE.md`.

## Trademark

**AstraeaOS™ is a trademark of VisionGaiaTechnology.** No registration of the mark is claimed by this release.

## Assets

- `astraeaos-wp-0.6.0-alpha-runtime.zip` — installable runtime tree.
- `astraeaos-wp-0.6.0-alpha-source.zip` — full source, docs, tests and build tooling.
- `SHA256SUMS` — SHA-256 checksums for release assets.
- `AstraeaOS_WP_Handbuch_Alpha-Reihe.pdf` — handbook valid for the alpha series.
