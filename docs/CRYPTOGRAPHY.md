# AstraeaOS WP — Cryptographic Core Architecture

**Distribution:** AstraeaOS WP `0.1.0-alpha`  
**Primary Engine:** Libsodium AEAD (XChaCha20-Poly1305)  
**Fallback Engine:** OpenSSL AEAD (AES-256-GCM)  
**Key Derivation:** HKDF-SHA256 (RFC 5869) with Context Separation  

---

## 1. Architectural Philosophy

1. **Authenticated Encryption with Associated Data (AEAD):** Every ciphertext is bound to an authentication tag (Poly1305 or GMAC). Any bit-level modification, truncating, or tampering immediately causes decryption to fail.
2. **Context Separation:** A single master key is never used directly across multiple subsystems. Separate, cryptographically independent subkeys are derived per domain.
3. **No Database Blind Encryption:** AstraeaOS strictly avoids whole-database encryption. Database indices, ordering, partial string matching, and foreign keys remain fast and queryable. Sensitive secrets are explicitly encrypted at the application boundary.
4. **Fail-Closed Operation:** If authentication verification fails, the engine throws `Astraea\Crypto\CryptoAuthenticationException`. It never silently outputs empty strings or returns unauthenticated data.

---

## 2. Master Key Architecture

The master key is the root of trust for an AstraeaOS installation.

### Storage Rules:
- **FORBIDDEN:** Storing the master key in MySQL/MariaDB options, user meta, configuration files committed to git, or client-side assets.
- **PERMITTED:**
  1. Environment variable: `ASTRAEA_MASTER_KEY="<64_hex_chars_or_32_bytes_base64>"`
  2. Protected external file: `define('ASTRAEA_MASTER_KEY_FILE', '/etc/astraea/master.key');`
  3. Server constant: `define('ASTRAEA_MASTER_KEY', '...');`

### Key Derivation Matrix (HKDF-SHA256):
Subkeys are derived deterministically using RFC 5869 HKDF:
$$\text{Subkey} = \text{HKDF-Expand}(\text{HKDF-Extract}(\text{Salt}, \text{MasterKey}), \text{ContextInfo}, 32)$$

| Domain Context | `KeyContext` Enum Value | Subsystem Boundary |
| :--- | :--- | :--- |
| `DATABASE_OPTIONS` | `astraea-db-options-v1` | Application-level Secure Options API |
| `GEDEFENSE` | `astraea-gedefense-v1` | GeDefense Security Kernel & Vault |
| `SECURITY_EVENTS` | `astraea-sec-events-v1` | TRINITY XDR Event Fabric Signatures |
| `SECRETS` | `astraea-app-secrets-v1` | General Application Secret Vault |
| `INTERNAL_TOKENS` | `astraea-tokens-v1` | Session tokens, Hades gate cookies |
| `PASSWORD_PEPPER` | `astraea-pepper-v1` | Secondary internal pepper derivation |

---

## 3. Ciphertext Envelope Serialization

Encrypted payloads adhere to a structured, URL-safe envelope format:

$$\texttt{vgt:a1:<algo>:<key\_id>:<nonce\_b64>:<ciphertext\_b64>:<tag\_b64>}$$

### Field Breakdown:
- **`vgt`**: AstraeaOS / VisionGaia technology namespace identifier.
- **`a1`**: Format version 1.
- **`algo`**: Algorithm indicator (`xc20p` for XChaCha20-Poly1305, `a256g` for AES-256-GCM).
- **`key_id`**: 8-character hexadecimal fingerprint of the master key. Allows instant detection of key rotation.
- **`nonce_b64`**: 24-byte (XChaCha20) or 12-byte (GCM) CSPRNG nonce in URL-safe base64.
- **`ciphertext_b64`**: Raw encrypted payload.
- **`tag_b64`**: 16-byte Poly1305 or GMAC authentication tag.

---

## 4. Additional Authenticated Data (AAD) Binding

To defeat **ciphertext transplantation attacks** (where an attacker copies valid ciphertext from one record/option to another), AstraeaOS binds cryptographic context into the AEAD tag calculation.

```php
// Binding option name into AAD
$aad = 'astraea:sec_opt:' . $optionName;
$envelope = CryptoService::encrypt($json, KeyContext::DATABASE_OPTIONS, $aad);
```

If an attacker moves this ciphertext to a different option in the database, decryption with the new option name fails authentication immediately.

---

## 5. Secure Options API

Complementing the core `get_option()` / `update_option()` functions, AstraeaOS provides a native Secure Options interface:

```php
// Storing an encrypted credential
astraea_secure_option_set('stripe_secret_key', 'sk_live_938472938472', false);

// Retrieving and decrypting
$apiKey = astraea_secure_option_get('stripe_secret_key');

// Deleting
astraea_secure_option_delete('stripe_secret_key');
```

Complex data structures (arrays, objects) are serialized via JSON with strict UTF-8 enforcement prior to encryption.
