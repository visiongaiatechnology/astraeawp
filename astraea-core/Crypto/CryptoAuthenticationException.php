<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Crypto;

use Astraea\Exceptions\SecurityException;

/**
 * Exception thrown when cryptographic authentication fails (tampering, wrong key, wrong AAD),
 * or when an envelope references an unknown or invalid keyId in Keyring.
 *
 * Inherits from SecurityException to enforce opaque client disclosure and internal security audit logging.
 *
 * @package Astraea\Crypto
 */
final class CryptoAuthenticationException extends SecurityException {}
