<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

if (!defined('ABSPATH')) exit('VGT_ACCESS_DENIED');

final class VIS_Svg_Xml_Detector implements VIS_File_Detector {
    public function detect(string $path, VIS_Scan_Context $context, VIS_Scan_Budget $budget): array {
        if ($context->extension !== 'svg' && $context->detectedMime !== 'image/svg+xml') return [];
        $size = @filesize($path);
        if ($size === false || $size <= 0) {
            return [new VIS_Scan_Finding(
                'SVG_READ_FAILURE', 35, 100, 'SVG payload could not be read.', false,
                VIS_Scan_Finding::CLASS_SUSPICIOUS
            )];
        }
        if ($size > $budget->maxBytes) {
            return [new VIS_Scan_Finding(
                'SVG_SIZE_BOUNDARY', 35, 100, 'SVG exceeds the bounded deep-inspection window.', false,
                VIS_Scan_Finding::CLASS_POLICY
            )];
        }
        $content = @file_get_contents($path);
        if (!is_string($content) || $content === '') {
            return [new VIS_Scan_Finding(
                'SVG_READ_FAILURE', 35, 100, 'SVG payload could not be read.', false,
                VIS_Scan_Finding::CLASS_SUSPICIOUS
            )];
        }

        $activePatterns = [
            '/<script\\b/i', '/\\son[a-z]+\\s*=/i', '/javascript\\s*:/i', '/<iframe\\b/i',
            '/<object\\b/i', '/<embed\\b/i', '/<foreignObject\\b/i',
            '/xlink:href\\s*=\\s*[\'\"]\\s*javascript:/i',
            '/(?:href|xlink:href)\\s*=\\s*[\'\"]\\s*data:(?:text\\/html|image\\/svg\\+xml)/i',
        ];
        foreach ($activePatterns as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return [new VIS_Scan_Finding(
                    'ACTIVE_SVG_PAYLOAD', 96, 98,
                    'SVG contains active scripting or executable embedded-content behavior.', true,
                    VIS_Scan_Finding::CLASS_MALWARE
                )];
            }
        }

        if (preg_match('/<!ENTITY\\s+[^>]*(?:SYSTEM|PUBLIC)/i', $content) === 1) {
            return [new VIS_Scan_Finding(
                'SVG_EXTERNAL_ENTITY', 92, 97,
                'SVG defines an external XML entity.', true,
                VIS_Scan_Finding::CLASS_MALWARE
            )];
        }

        $findings = [];
        // A legacy SVG font may legitimately carry a public SVG DTD. It is not malware evidence by itself.
        if (preg_match('/<!DOCTYPE\\s+svg\\b/i', $content) === 1) {
            $findings[] = new VIS_Scan_Finding(
                'SVG_DOCTYPE_PRESENT', 15, 100,
                'SVG contains a document type declaration; network entity resolution is disabled during parsing.', false,
                VIS_Scan_Finding::CLASS_INFO
            );
        }

        if (class_exists('DOMDocument')) {
            $previous = libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $loaded = $dom->loadXML($content, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (!$loaded || strtolower((string)($dom->documentElement?->localName ?? '')) !== 'svg') {
                $findings[] = new VIS_Scan_Finding(
                    'SVG_MALFORMED', 30, 100,
                    'SVG is malformed XML or not rooted in an SVG element; browsers may still parse it tolerantly.', false,
                    VIS_Scan_Finding::CLASS_SUSPICIOUS
                );
            }
        }
        return $findings;
    }
}
