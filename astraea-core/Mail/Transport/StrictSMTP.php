<?php
// STATUS: DIAMANT VGT SUPREME

declare(strict_types=1);

namespace Astraea\Mail\Transport;

use PHPMailer\PHPMailer\SMTP;

/**
 * Astraea SMTP protocol adapter.
 *
 * Keeps WordPress' bundled PHPMailer SMTP parser/state machine while replacing
 * STARTTLS negotiation with an explicit TLS 1.2/1.3-only policy.
 */
class StrictSMTP extends SMTP
{
    private string $pinnedHost = '';
    /** @var list<string> */
    private array $pinnedAddresses = [];

    /** @param list<string> $addresses */
    public function pinEndpoint(string $host, array $addresses): void
    {
        $normalized = \Astraea\Mail\Security\EndpointPolicy::normalizeHost($host);
        $validated = [];
        foreach ($addresses as $address) {
            if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new \InvalidArgumentException('SMTP pin set contains an invalid address.');
            }
            $validated[] = strtolower($address);
        }
        if ($normalized === '' || $validated === []) {
            throw new \InvalidArgumentException('SMTP pin set is empty.');
        }
        $this->pinnedHost = $normalized;
        $this->pinnedAddresses = array_values(array_unique($validated));
    }

    public function startTLS()
    {
        if (!$this->sendCommand('STARTTLS', 'STARTTLS', 220)) {
            return false;
        }
        $method = 0;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $method |= (int)STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $method |= (int)STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        if ($method === 0) {
            $this->setError('TLS 1.2+ crypto methods are unavailable in this PHP runtime.');
            return false;
        }
        set_error_handler(function () {
            call_user_func_array([$this, 'errorHandler'], func_get_args());
        });
        try {
            $ok = stream_socket_enable_crypto($this->smtp_conn, true, $method);
        } finally {
            restore_error_handler();
        }
        return (bool)$ok;
    }
    public function authenticate($username, $password, $authtype = null, $OAuth = null)
    {
        if (is_string($authtype) && $authtype !== '' && is_array($this->server_caps)) {
            $requested = strtoupper($authtype);
            $advertised = $this->server_caps['AUTH'] ?? null;
            if (is_array($advertised)) {
                $normalized = array_map(static fn($value): string => strtoupper((string)$value), $advertised);
                if (!in_array($requested, $normalized, true)) {
                    $this->setError('Configured SMTP authentication mechanism is not advertised by the server.');
                    return false;
                }
            }
        }
        return parent::authenticate($username, $password, $authtype, $OAuth);
    }

    /**
     * Establish SMTP stream connection with IP pinning to prevent DNS rebinding TOCTOU.
     */
    protected function getSMTPConnection($host, $port = null, $timeout = 30, $options = [])
    {
        $transportPrefix = str_starts_with((string)$host, 'ssl://') ? 'ssl://' : '';
        $logicalHost = preg_replace('#\A(?:ssl|tls)://#i', '', (string)$host);
        $logicalHost = is_string($logicalHost) ? strtolower($logicalHost) : '';
        if ($logicalHost === '' || $this->pinnedHost === '' || !hash_equals($this->pinnedHost, $logicalHost) || $this->pinnedAddresses === []) {
            $this->setError('SMTP connection rejected because no matching preflight pin exists.');
            return false;
        }

        $targetHost = $this->pinnedAddresses[0];
        if (filter_var($targetHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) $targetHost = '[' . $targetHost . ']';
        $targetHost = $transportPrefix . $targetHost;
        $options['ssl']['peer_name'] = $this->pinnedHost;
        $options['ssl']['SNI_enabled'] = true;

        $errno = 0;
        $errstr = '';
        $socket_context = stream_context_create($options);
        set_error_handler(function () {
            call_user_func_array([$this, 'errorHandler'], func_get_args());
        });
        try {
            $connection = stream_socket_client(
                $targetHost . ':' . $port,
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT,
                $socket_context
            );
        } finally {
            restore_error_handler();
        }

        return $connection;
    }
}
