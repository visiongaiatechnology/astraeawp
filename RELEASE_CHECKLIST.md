# Public Alpha Release Checklist

- [x] Removed accidental `powershell.exe` / `powershell.cmd` artifacts.
- [x] Removed obsolete build report and generated runtime boot state from the source tree.
- [x] Replaced upstream WordPress HTML readmes with AstraeaOS-specific offline readmes while retaining upstream `license.txt`.
- [x] Added AGPLv3 license text and explicit component-scoped licensing overview.
- [x] Added trademark and third-party notices.
- [x] Added GitHub issue / pull-request templates.
- [x] Added alpha-series handbook to source docs and release assets.
- [x] Generate final manifests after all file changes.
- [x] Run release invariant checks (98/98 in the packaging environment).
- [x] Run PHP/JS syntax checks (1993 PHP / 662 JS in the clean source tree).
- [x] Package source/runtime archives and compute SHA-256.
- [x] Fresh-extract archives and verify manifests.
- [ ] Attach assets to a GitHub **prerelease**.
- [ ] For authenticated updater distribution, sign with the real offline VGT Ed25519 release key.
