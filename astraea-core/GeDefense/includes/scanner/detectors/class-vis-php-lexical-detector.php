<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

if (!defined('ABSPATH')) exit('VGT_ACCESS_DENIED');

final class VIS_Php_Lexical_Detector implements VIS_File_Detector {
    private const MAX_ANALYSIS_BYTES = 1048576;

    public function detect(string $path, VIS_Scan_Context $context, VIS_Scan_Budget $budget): array {
        $content = $this->readBounded($path, min($budget->maxBytes, self::MAX_ANALYSIS_BYTES));
        if ($content === '') return [];
        return $this->analyzeContent($content, $context);
    }

    /** @return list<VIS_Scan_Finding> */
    public function analyzeContent(string $content, VIS_Scan_Context $context): array {
        $relPath = strtolower(str_replace('\\', '/', $context->relativePath));

        // Scanner signature source is excluded from self-signature matching; it is still covered by integrity hashes.
        if (str_contains($relPath, 'includes/scanner/')) return [];

        $hasEmbeddedPhp = $context->isExecutableExtension()
            || $this->containsExecutablePhpTag($content, $context->extension);
        $findings = $this->detectKnownMarkers($content);

        if (!$hasEmbeddedPhp && !$context->isExecutableExtension()) return $findings;

        if (!$context->isExecutableExtension() && $hasEmbeddedPhp) {
            $findings[] = new VIS_Scan_Finding(
                'EMBEDDED_PHP_PAYLOAD',
                82,
                95,
                'A PHP execution marker exists outside a server-executable PHP extension.',
                false,
                VIS_Scan_Finding::CLASS_SUSPICIOUS
            );
        }

        if ($this->getAvailableMemory() < 8388608) return $findings;

        // Analyze executable PHP tokens only. Comments and string/heredoc payloads are intentionally removed
        // so test fixtures, documentation examples and binary/minified JS strings cannot masquerade as RCE.
        $analysisCode = $this->phpCodeWithoutLiterals($content);
        if ($analysisCode === '') return $findings;

        $execPats = '(?:eval|assert|system|exec|shell_exec|passthru|popen|proc_open)';
        $decPats  = '(?:base64_decode|gzinflate|gzuncompress|str_rot13|hex2bin|convert_uudecode)';
        $inputPats = '\\$_(?:GET|POST|REQUEST|COOKIE|SERVER)';
        $writePats = '(?:file_put_contents|fwrite|fputs)';

        $chainRegex = '/\\b' . $execPats . '\\s*\\(\\s*' . $decPats . '\\s*\\(/i';
        if (preg_match($chainRegex, $analysisCode) === 1) {
            $findings[] = new VIS_Scan_Finding(
                'DECODE_EXECUTION_CHAIN', 98, 96,
                'Decoded content flows directly into a dynamic execution primitive.', true,
                VIS_Scan_Finding::CLASS_MALWARE
            );
        }

        $rceRegex1 = '/\\b' . $execPats . '\\s*\\([^;]*?' . $inputPats . '\\b/i';
        $rceRegex2 = '/\\$_(?:GET|POST|REQUEST|COOKIE)\\s*\\[[^\\]]+\\]\\s*\\(/i';
        if (preg_match($rceRegex1, $analysisCode) === 1 || preg_match($rceRegex2, $analysisCode) === 1) {
            $findings[] = new VIS_Scan_Finding(
                'REMOTE_EXECUTION_FLOW', 99, 96,
                'External request data flows directly into a dynamic execution primitive.', true,
                VIS_Scan_Finding::CLASS_MALWARE
            );
        }

        $dropperRegex = '/\\b' . $writePats . '\\s*\\([^;]*?' . $inputPats . '\\b/i';
        $uploadRegex = '/\\bmove_uploaded_file\\s*\\([^,]+,\\s*[^;]*\\.php/i';
        if (preg_match($dropperRegex, $analysisCode) === 1
            || (preg_match($uploadRegex, $analysisCode) === 1 && !str_contains($relPath, 'airlock'))) {
            $findings[] = new VIS_Scan_Finding(
                'REMOTE_FILE_DROPPER_FLOW', 97, 92,
                'External request data flows directly into executable file creation.', true,
                VIS_Scan_Finding::CLASS_MALWARE
            );
        }

        $dynCallRegex = '/(?:\\$[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*)\\s*=\\s*' . $decPats . '\\s*\\([^;]+\\);\\s*\\$[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*\\s*\\(/i';
        if (preg_match($dynCallRegex, $analysisCode) === 1) {
            $findings[] = new VIS_Scan_Finding(
                'OBFUSCATED_DYNAMIC_CALL', 88, 84,
                'Decoded data and variable function execution occur together in sequence.', true,
                VIS_Scan_Finding::CLASS_MALWARE
            );
        }

        $highEntropyExec = '/\\b' . $execPats . '\\b/i';
        if ($this->hasHighEntropyPayload($content) && preg_match($highEntropyExec, $analysisCode) === 1) {
            $findings[] = new VIS_Scan_Finding(
                'HIGH_ENTROPY_EXECUTABLE_BLOB', 82, 78,
                'High-entropy content is combined with execution primitives and requires review.', false,
                VIS_Scan_Finding::CLASS_SUSPICIOUS
            );
        }

        return $findings;
    }

    /** @return list<VIS_Scan_Finding> */
    private function detectKnownMarkers(string $content): array {
        $lower = strtolower($content);
        $markers = ['c99' . 'shell', 'r57' . 'shell', 'wso' . ' ' . 'shell', 'files' . 'man', 'b374' . 'k', 'indox' . 'ploit'];
        foreach ($markers as $marker) {
            if (str_contains($lower, $marker)) {
                return [new VIS_Scan_Finding(
                    'KNOWN_WEBSHELL_MARKER', 100, 99,
                    'Known webshell family marker detected.', true,
                    VIS_Scan_Finding::CLASS_MALWARE
                )];
            }
        }
        return [];
    }

    private function containsExecutablePhpTag(string $content, string $extension): bool {
        $extension = strtolower($extension);
        if ($extension === 'js') return $this->containsPhpTagOutsideJavaScriptLiteral($content);

        $withoutHtmlComments = preg_replace('/<!--.*?-->/s', '', $content);
        if (!is_string($withoutHtmlComments)) $withoutHtmlComments = $content;
        return stripos($withoutHtmlComments, '<?php') !== false
            || preg_match('/<\\?=(?!=)/', $withoutHtmlComments) === 1;
    }

    private function containsPhpTagOutsideJavaScriptLiteral(string $content): bool {
        $length = strlen($content);
        $state = 'normal';
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $c = $content[$i];
            $n = $i + 1 < $length ? $content[$i + 1] : '';

            if ($state === 'line_comment') {
                if ($c === "\n" || $c === "\r") $state = 'normal';
                continue;
            }
            if ($state === 'block_comment') {
                if ($c === '*' && $n === '/') { $state = 'normal'; $i++; }
                continue;
            }
            if (in_array($state, ['single', 'double', 'template'], true)) {
                if ($escaped) { $escaped = false; continue; }
                if ($c === '\\') { $escaped = true; continue; }
                $terminator = $state === 'single' ? "'" : ($state === 'double' ? '"' : '`');
                if ($c === $terminator) $state = 'normal';
                continue;
            }

            if ($c === '/' && $n === '/') { $state = 'line_comment'; $i++; continue; }
            if ($c === '/' && $n === '*') { $state = 'block_comment'; $i++; continue; }
            if ($c === "'") { $state = 'single'; continue; }
            if ($c === '"') { $state = 'double'; continue; }
            if ($c === '`') { $state = 'template'; continue; }

            if ($c === '<' && $n === '?') {
                $tail = strtolower(substr($content, $i, 6));
                if (str_starts_with($tail, '<?php') || str_starts_with(substr($content, $i, 3), '<?=')) return true;
            }
        }
        return false;
    }

    private function phpCodeWithoutLiterals(string $content): string {
        $tokens = token_get_all($content);
        $out = '';
        $quoted = null;
        $heredoc = false;

        foreach ($tokens as $token) {
            if (is_string($token)) {
                if ($quoted !== null) {
                    if ($token === $quoted) $quoted = null;
                    continue;
                }
                if ($token === '"' || $token === '`') {
                    $quoted = $token;
                    continue;
                }
                $out .= $token;
                continue;
            }

            [$id, $text] = $token;
            if ($id === T_START_HEREDOC) { $heredoc = true; continue; }
            if ($id === T_END_HEREDOC) { $heredoc = false; continue; }
            if ($heredoc || $quoted !== null) continue;
            if (in_array($id, [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) continue;
            if (defined('T_OPEN_TAG_WITH_ECHO') && $id === T_OPEN_TAG_WITH_ECHO) { $out .= ' echo '; continue; }
            if ($id === T_OPEN_TAG || $id === T_CLOSE_TAG) continue;
            $out .= $text;
        }
        return $out;
    }

    private function readBounded(string $path, int $maxBytes): string {
        $fileSize = @filesize($path);
        if ($fileSize === false || $fileSize === 0) return '';
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) return '';

        if ($fileSize <= $maxBytes) {
            $content = '';
            while (!feof($handle) && strlen($content) < $maxBytes) {
                $chunk = fread($handle, min(65536, $maxBytes - strlen($content)));
                if (!is_string($chunk) || $chunk === '') break;
                $content .= $chunk;
            }
            fclose($handle);
            return $content;
        }

        $half = (int) floor($maxBytes / 2);
        $head = fread($handle, $half);
        $head = is_string($head) ? $head : '';
        $tail = '';
        if (@fseek($handle, -$half, SEEK_END) === 0) {
            $tail = fread($handle, $half);
            $tail = is_string($tail) ? $tail : '';
        }
        fclose($handle);
        return $head . "\n/* VGT_BOUNDED_SCAN_GAP */\n" . $tail;
    }

    private function hasHighEntropyPayload(string $content): bool {
        if (preg_match_all('/[A-Za-z0-9+\\/=]{256,}/', $content, $matches) < 1) return false;
        foreach (array_slice($matches[0], 0, 8) as $candidate) {
            $length = strlen($candidate);
            if ($length === 0) continue;
            $counts = count_chars($candidate, 1);
            $entropy = 0.0;
            foreach ($counts as $count) {
                $probability = $count / $length;
                $entropy -= $probability * log($probability, 2);
            }
            if ($entropy >= 5.8) return true;
        }
        return false;
    }

    private function getAvailableMemory(): int {
        $limitStr = ini_get('memory_limit');
        if (!$limitStr || $limitStr === '-1') return 268435456;
        $last = strtolower(substr($limitStr, -1));
        $val = (int) $limitStr;
        if ($last === 'g') $val *= 1024 * 1024 * 1024;
        elseif ($last === 'm') $val *= 1024 * 1024;
        elseif ($last === 'k') $val *= 1024;
        return max(0, $val - memory_get_usage(true));
    }
}
