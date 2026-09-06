<?php
// STATUS: DIAMANT VGT SUPREME

declare(strict_types=1);

namespace Astraea\Mail\Diagnostics;

use Astraea\Mail\Transport\StrictSMTP;

final class InspectableSMTP extends StrictSMTP
{
    /** @return array<string,mixed> */
    public function transportMeta(): array
    {
        if (!is_resource($this->smtp_conn)) {
            return [];
        }
        $meta = stream_get_meta_data($this->smtp_conn);
        $params = stream_context_get_params($this->smtp_conn);
        $result = [];
        if (isset($meta['crypto']) && is_array($meta['crypto'])) {
            $result['crypto'] = $meta['crypto'];
        }
        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
        if ($certificate !== null && function_exists('openssl_x509_parse')) {
            $parsed = @openssl_x509_parse($certificate, false);
            if (is_array($parsed)) {
                $result['certificate'] = $parsed;
            }
        }
        return $result;
    }
}
