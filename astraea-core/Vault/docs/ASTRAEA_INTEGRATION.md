# AstraeaOS WP Integration

## Validated source target

This build was adapted against the user-supplied AstraeaOS WP tree with:

- Astraea core version: `0.3.0-alpha`
- WordPress core version: `7.1`
- observed git HEAD: `75a9dbb`

## Native services consumed

### `Astraea\Crypto\MasterKeyManager`
Vault derives a dedicated unattended service key using an additional Vault-specific HKDF context. The Astraea master key itself is not persisted by Vault.

### `Astraea\Auth\Argon2idPolicy`
Vault reads the active Astraea memory/time policy and clamps it to Vault's libsodium-compatible safety bounds.

### `Astraea\Security\Logger`
Structured Vault logs are routed through Astraea's redacting logger when present.

### `VisionGaia\GeDefense\Core\EventBus`
Security-relevant Vault events are emitted into GeDefense without making recovery depend on GeDefense availability.

### Astraea Glass
Vault CSS consumes Astraea design tokens with safe standalone fallbacks.

## Core-native integration

No external patch command is required in this source tree. Vault is already part of `astraea-core/Vault/`, and the current `wp-settings.php` contains the Phase-D pre-plugin recovery gate.

For future rebases, treat the Phase-D gate as a small, explicit upstream delta and revalidate it semantically against the new WordPress boot order rather than applying an old line-number patch.

## Why this location

The earliest Astraea core bootstrap in the supplied fork runs before DB initialization. That is too early for Vault recovery because recovery needs WordPress options and `$wpdb`.

The selected location runs after:

- WordPress functions/options API;
- database initialization;
- object cache;
- multisite bootstrap;
- plugin directory constants.

It runs before:

- must-use plugins;
- network-active plugins;
- normal active plugins.

This is the earliest practical recovery boundary for the current Vault design.

## Upstream maintenance

Keep the core change as a dedicated patch/commit. On every future AstraeaOS WP rebase, revalidate the gate is still after DB/options/multisite setup and before plugin loading. Do not blindly reapply by line number.
