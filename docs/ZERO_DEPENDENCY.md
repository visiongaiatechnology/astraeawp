# Astraea Zero-Dependency Runtime Doctrine

Astraea's security-critical runtime must boot and enforce local protections without Composer, npm, CDN assets, remote fonts or a required SaaS/API.

## Required runtime primitives

Only the WordPress/PHP platform and explicitly required native PHP extensions are used by Astraea Core, Admin UI, authentication, integrity verification and Vault.

## UI

- no CDN scripts,
- no CDN styles,
- no external fonts,
- no dynamic HTML insertion from user/database values through `innerHTML`,
- assets are local and versioned.

## Cryptography

Uses PHP native password APIs, OpenSSL and libsodium. No JavaScript cryptography library or remote crypto service is required.

## Optional network integrations

Some inherited GeDefense modules can expose optional user-configured network integrations. They are not dependencies of Astraea boot, authentication, FileGuard, Titan local policy, Vault, integrity verification or local recovery. They must remain explicit/optional and may not silently turn into a required control in Security Center health computation.
