<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Diagnostics;

use Astraea\Bootstrap\RuntimeCheck;
use Astraea\Crypto\MasterKeyManager;
use Astraea\Crypto\CryptoService;
use Astraea\Auth\PepperManager;
use Astraea\Auth\Argon2idPolicy;
use Astraea\Options\OptionsGuard;
use Astraea\Performance\LegacyPruner;

/**
 * AstraeaOS System Health & Diagnostics Engine.
 *
 * Evaluates host security, runtime compatibility, cryptographic state,
 * database integrity, and defense subsystem status.
 *
 * @package Astraea\Diagnostics
 */
final class SystemHealth {

    /**
     * Compile a comprehensive system health snapshot.
     *
     * @return array<string, mixed>
     */
    public static function audit(): array {
        return [
            'astraea_version' => defined('ASTRAEA_VERSION') ? ASTRAEA_VERSION : 'unknown',
            'runtime' => [
                'php_version' => PHP_VERSION,
                'architecture' => PHP_INT_SIZE === 8 ? '64-bit' : '32-bit',
                'sapi' => PHP_SAPI,
                'checks' => RuntimeCheck::check(),
            ],
            'cryptography' => [
                'master_key_configured' => MasterKeyManager::isConfigured(),
                'key_identifier'        => MasterKeyManager::isConfigured() ? MasterKeyManager::getKeyIdentifier() : 'unconfigured',
                'primary_aead_algo'     => function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt') ? 'XChaCha20-Poly1305 (libsodium)' : 'AES-256-GCM (OpenSSL)',
                'password_pepper_active'=> PepperManager::isEnabled(),
                'argon2id_policy'       => Argon2idPolicy::getOptions(),
            ],
            'database' => [
                'schema_version'        => get_option('astraea_db_version', 'uninstalled'),
                'options_hygiene'       => OptionsGuard::inspectAutoload(),
            ],
            'gedefense' => [
                'kernel_active'         => defined('VIS_VERSION'),
                'kernel_version'        => defined('VIS_VERSION') ? VIS_VERSION : 'inactive',
                'cerberus_active'       => class_exists('VIS_Cerberus', false),
                'aegis_active'          => defined('VIS_AEGIS_ACTIVE'),
            ],
            'hardening' => [
                'xmlrpc_enabled'        => LegacyPruner::isXmlRpcEnabled(),
                'pingbacks_enabled'     => LegacyPruner::isPingbackEnabled(),
                'emojis_enabled'        => LegacyPruner::isEmojiEnabled(),
                'file_edit_disabled'    => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT,
            ],
        ];
    }
}
