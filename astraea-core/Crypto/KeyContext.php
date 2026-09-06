<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Crypto;

/**
 * Enumeration of Cryptographic Domain Separation Contexts.
 *
 * Ensures cryptographic subkeys derived from the master key
 * are mathematically distinct and strictly isolated per subsystem.
 *
 * @package Astraea\Crypto
 */
enum KeyContext: string {
    case DATABASE_OPTIONS = 'astraea-db-options-v1';
    case GEDEFENSE        = 'astraea-gedefense-v1';
    case SECURITY_EVENTS  = 'astraea-sec-events-v1';
    case SECRETS          = 'astraea-app-secrets-v1';
    case INTERNAL_TOKENS  = 'astraea-tokens-v1';
    case PASSWORD_PEPPER  = 'astraea-pepper-v1';
    case VAULT_MASTER     = 'astraea:vault:master:v1';
    case VAULT_SERVICE    = 'astraea:vault:service:v1';
    case VAULT_BACKUP     = 'astraea:vault:backup:v1';
    case VLP_CONSENT      = 'astraea:vlp:consent:v1';
    case VLP_DATTRACK     = 'astraea:vlp:dattrack:v1';
    case MAIL_TRANSPORT    = 'astraea:mail:transport:v1';
    case FORMS_SUBMISSION  = 'astraea:forms:submission:v1';

    /**
     * Get the HKDF info context string.
     */
    public function info(): string {
        return $this->value;
    }

    /**
     * Get a human-readable identifier for diagnostics.
     */
    public function label(): string {
        return match ($this) {
            self::DATABASE_OPTIONS => 'Database Secure Options',
            self::GEDEFENSE        => 'GeDefense Security Kernel',
            self::SECURITY_EVENTS  => 'Security Event Fabric',
            self::SECRETS          => 'Application Secrets Vault',
            self::INTERNAL_TOKENS  => 'Internal Session & Gate Tokens',
            self::PASSWORD_PEPPER  => 'Password Hashing Pepper',
            self::VAULT_MASTER     => 'Astraea Vault Master Key',
            self::VAULT_SERVICE    => 'Astraea Vault Service Key',
            self::VAULT_BACKUP     => 'Astraea Vault Backup Data Key',
            self::VLP_CONSENT      => 'VLP Light Consent Receipt',
            self::VLP_DATTRACK     => 'VLP Light Dattrack Analytics',
            self::MAIL_TRANSPORT    => 'Astraea Mail Gateway Transport',
            self::FORMS_SUBMISSION  => 'Astraea Forms Submission Storage',
        };
    }
}
