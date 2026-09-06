<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Media;

use Astraea\Exceptions\SecurityException;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Hardened SVG Sanitizer and Active Vector Analyzer.
 *
 * Enforces strict XML parsing, entity expansion disabling, recursive DOM
 * element and attribute inspection, and protocol validation for SVG graphics.
 *
 * @package Astraea\Media
 */
final class SvgSanitizer {

    public const ALLOWED_TAGS = [
        'svg', 'g', 'path', 'circle', 'rect', 'line', 'polygon', 'polyline',
        'ellipse', 'text', 'tspan', 'defs', 'clippath', 'mask',
        'lineargradient', 'radialgradient', 'stop', 'pattern', 'title', 'desc'
    ];

    public const ALLOWED_ATTRIBUTES = [
        'id', 'class', 'width', 'height', 'viewbox', 'fill', 'fill-opacity',
        'fill-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
        'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity',
        'opacity', 'transform', 'd', 'x', 'y', 'x1', 'y1', 'x2', 'y2',
        'cx', 'cy', 'r', 'rx', 'ry', 'points', 'dx', 'dy', 'text-anchor',
        'font-family', 'font-size', 'font-weight', 'clip-path', 'mask',
        'gradientunits', 'gradienttransform', 'spreadmethod', 'offset',
        'stop-color', 'stop-opacity', 'patternunits', 'patterntransform',
        'patterncontentunits', 'xmlns', 'xmlns:xlink', 'version', 'preserveaspectratio'
    ];

    private const DANGEROUS_PROTOCOLS = [
        'javascript:', 'vbscript:', 'data:', 'file:', 'expect:', 'php:', 'glob:'
    ];

    /**
     * Inspect raw SVG content for security threats.
     *
     * @return bool True if threat detected, false if clean
     */
    public static function hasThreats(string $content): bool {
        // 0. Reject null bytes or dangerous BOM
        if (str_contains($content, "\0") || str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
            return true;
        }

        // 1. Check for DOCTYPE or ENTITY expansion (XXE)
        if (preg_match('/<!\s*(?:doctype|entity)\b/i', $content)) {
            return true;
        }

        // 2. Quick pre-filter for scripts, styles, foreignObject, iframe, use
        if (preg_match('/<\s*(?:script|style|foreignobject|object|iframe|embed|applet|use|image|audio|video|animate|set|handler|listener|discard)\b/i', $content)) {
            return true;
        }

        // 3. Check for inline event handlers (on*)
        if (preg_match('/\bon[a-z0-9_-]+\s*=/i', $content)) {
            return true;
        }

        // 4. Quick regex check for dangerous URI schemes or external URLs
        if (preg_match('/(href|src|xlink:href|action|formaction)\s*=\s*[\'"]\s*(javascript|data|vbscript|file):/i', $content)) {
            return true;
        }

        // 5. Deep inspection via DOM parser
        return self::inspectDomStructure($content);
    }

    /**
     * Sanitize SVG file content or throw SecurityException if irrecoverable threat exists.
     *
     * @param string $content
     * @return string Sanitized XML
     * @throws SecurityException
     */
    public static function sanitize(string $content): string {
        if (self::hasThreats($content)) {
            throw new SecurityException('SVG contains malicious active vectors (scripts, event handlers, or XXE). Blocked.');
        }

        // Parse with strict DOMDocument without external entities
        $dom = new DOMDocument();
        $dom->formatOutput = false;
        $dom->preserveWhiteSpace = false;

        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($content, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded) {
            throw new SecurityException('Malformed SVG XML structure.');
        }

        $root = $dom->documentElement;
        if ($root === null || strtolower($root->nodeName) !== 'svg') {
            throw new SecurityException('Invalid SVG root element.');
        }

        // Sanitize all elements recursively in DOM tree
        self::sanitizeNode($root);

        $cleanXml = $dom->saveXML($root);
        if (!is_string($cleanXml)) {
            throw new SecurityException('Failed to serialize sanitized SVG.');
        }

        return $cleanXml;
    }

    private static function inspectDomStructure(string $content): bool {
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($content, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded || $dom->documentElement === null) {
            return false;
        }

        return self::nodeHasThreats($dom->documentElement);
    }

    private static function nodeHasThreats(DOMNode $node): bool {
        if ($node instanceof DOMElement) {
            $tagName = strtolower($node->localName ?? $node->nodeName);
            if (!in_array($tagName, self::ALLOWED_TAGS, true)) {
                return true;
            }

            if ($node->hasAttributes()) {
                foreach ($node->attributes as $attr) {
                    $attrName = strtolower($attr->name);
                    if (!in_array($attrName, self::ALLOWED_ATTRIBUTES, true)) {
                        return true;
                    }

                    if (str_starts_with($attrName, 'on')) {
                        return true;
                    }

                    $decodedVal = html_entity_decode($attr->value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $sanitizedVal = strtolower((string)preg_replace('/[\x00-\x20\s]+/', '', $decodedVal));

                    foreach (self::DANGEROUS_PROTOCOLS as $proto) {
                        if (str_contains($sanitizedVal, $proto)) {
                            return true;
                        }
                    }

                    // Drop external network references
                    if (preg_match('/url\s*\(\s*[\'"]?\s*(?:https?:|\/\/)/i', $decodedVal)) {
                        return true;
                    }
                }
            }
        }

        foreach ($node->childNodes as $child) {
            if (self::nodeHasThreats($child)) {
                return true;
            }
        }

        return false;
    }

    private static function sanitizeNode(DOMNode $node): void {
        if ($node instanceof DOMElement) {
            $tagName = strtolower($node->localName ?? $node->nodeName);
            if (!in_array($tagName, self::ALLOWED_TAGS, true)) {
                $node->parentNode?->removeChild($node);
                return;
            }

            if ($node->hasAttributes()) {
                $toRemove = [];
                foreach ($node->attributes as $attr) {
                    $attrName = strtolower($attr->name);
                    if (!in_array($attrName, self::ALLOWED_ATTRIBUTES, true) || str_starts_with($attrName, 'on')) {
                        $toRemove[] = $attr->name;
                        continue;
                    }

                    $decodedVal = html_entity_decode($attr->value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $sanitizedVal = strtolower((string)preg_replace('/[\x00-\x20\s]+/', '', $decodedVal));

                    foreach (self::DANGEROUS_PROTOCOLS as $proto) {
                        if (str_contains($sanitizedVal, $proto)) {
                            $toRemove[] = $attr->name;
                            break;
                        }
                    }

                    if (preg_match('/url\s*\(\s*[\'"]?\s*(?:https?:|\/\/)/i', $decodedVal)) {
                        $toRemove[] = $attr->name;
                    }
                }

                foreach ($toRemove as $badAttr) {
                    $node->removeAttribute($badAttr);
                }
            }
        }

        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            self::sanitizeNode($child);
        }
    }
}
