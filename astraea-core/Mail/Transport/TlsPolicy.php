<?php
// STATUS: DIAMANT VGT SUPREME

declare(strict_types=1);

namespace Astraea\Mail\Transport;

final class TlsPolicy
{
    /** @return array<string,array<string,mixed>> */
    public static function streamOptions(string $peerName): array
    {
        $cryptoMethod = 0;
        foreach (['STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT', 'STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT'] as $constant) {
            if (defined($constant)) {
                $cryptoMethod |= (int)constant($constant);
            }
        }
        if ($cryptoMethod === 0 && defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')) {
            $cryptoMethod = (int)STREAM_CRYPTO_METHOD_TLS_CLIENT;
        }

        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $peerName,
            'SNI_enabled' => true,
            'disable_compression' => true,
            'capture_peer_cert' => true,
            'capture_peer_cert_chain' => false,
        ];
        if ($cryptoMethod > 0) {
            $ssl['crypto_method'] = $cryptoMethod;
        }
        return ['ssl' => $ssl];
    }
}
