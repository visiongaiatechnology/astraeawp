# Threat Model

## Stolen `.avb` backup

Expected protection: confidentiality and integrity remain protected by AES-256-GCM and the password/recovery envelope. Public header metadata is intentionally limited but not secret.

## Read-only backup-storage compromise

Expected protection: attacker can copy/delete what their access allows, but cannot decrypt payloads without a valid key path. Integrity verification detects modified/truncated artifacts.

## Read-only database compromise

The attacker can obtain the wrapped keyring and KDF parameters but not the Vault passphrase or plaintext master key. Offline passphrase guessing remains possible against the password slot, which is why a high-entropy passphrase and Argon2id are required.

## Compromised WordPress administrator

WordPress administration rights alone do not expose plaintext backup content. Restore, backup download and deletion require Vault-passphrase reauthentication and are rate limited. An administrator can still alter application state and may be able to disable the module; this is not treated as equivalent to possession of the Vault decryption secret.

## Malicious plugin

A plugin executing as the same PHP/OS user shares the WordPress process security boundary. It may attempt to call WordPress APIs, read accessible files or influence requests. Vault reduces exposure through private storage, separate key wrapping, early recovery, scoped rollback and explicit capabilities, but PHP plugin isolation is not a process sandbox.

## Full server-user/root compromise

A live server compromise can access locally available unattended service-key material and process memory. Local encryption cannot claim to defeat an attacker who fully controls the runtime. Off-host/immutable storage and external secret management are required for stronger resilience.

## Ransomware/destructive storage compromise

Local backups alone are insufficient if an attacker can delete the entire server. The current alpha implements hardened local encrypted storage; remote immutable storage providers are intentionally outside this build and can be added behind a future provider interface.

## Denial of service

Mitigations include bounded Argon2id settings, passphrase attempt limiting, chunked file handling, DB batching, free-space reserves, bounded AVB record sizes, staging space checks, lock coordination and bounded update transaction history.
