<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace VisionGaia\GeDefense\Modules\Gorgon;

if (!defined('ABSPATH')) exit('VGT Protocol: Direct access denied.');

/** Closed threat-pattern language: literal UTF-8 tokens only. */
final class Gorgon_Pattern_Dsl {
    private const MAX_PATTERNS = 256;
    private const MAX_LITERAL_BYTES = 256;

    /** @return array{schema:string,patterns:array<string,array{type:string,signature:string}>} */
    public static function sanitize_matrix(array $matrix): array {
        $source = isset($matrix['patterns']) && is_array($matrix['patterns']) ? $matrix['patterns'] : [];
        $patterns = [];

        foreach ($source as $name => $definition) {
            if (count($patterns) >= self::MAX_PATTERNS) break;
            if (!is_array($definition)) continue;

            $literal = isset($definition['signature']) && is_string($definition['signature']) ? trim($definition['signature']) : '';
            if ($literal === '' || strlen($literal) > self::MAX_LITERAL_BYTES || preg_match('//u', $literal) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $literal) === 1) continue;

            $safe_name = strtoupper((string)preg_replace('/[^A-Z0-9_-]/i', '_', (string)$name));
            $safe_name = substr(trim($safe_name, '_'), 0, 64);
            if ($safe_name === '') continue;

            $patterns[$safe_name] = ['type' => 'literal', 'signature' => $literal];
        }

        return ['schema' => 'literal-v1', 'patterns' => $patterns];
    }
}
