<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

if (!defined('ABSPATH')) exit('VGT_ACCESS_DENIED');

final class VIS_Path_Context_Detector implements VIS_File_Detector {
    private const GUARD_MAX_BYTES = 8192;

    public function detect(string $path, VIS_Scan_Context $context, VIS_Scan_Budget $budget): array {
        $relative = strtolower(str_replace('\\', '/', ltrim($context->relativePath, '/')));
        $findings = [];

        if ($context->isExecutableExtension() && preg_match('~(?:^|/)wp-content/uploads(?:/|$)~', $relative) === 1) {
            if ($this->isInertUploadGuard($path, $context)) {
                $findings[] = new VIS_Scan_Finding(
                    'INERT_UPLOAD_GUARD',
                    5,
                    100,
                    'Minimal non-mutating guard file exists in the upload tree.',
                    false,
                    VIS_Scan_Finding::CLASS_INFO
                );
            } else {
                $findings[] = new VIS_Scan_Finding(
                    'EXECUTABLE_IN_UPLOADS',
                    92,
                    96,
                    'Executable server-side code exists inside the media upload tree and requires review.',
                    false,
                    VIS_Scan_Finding::CLASS_POLICY
                );
            }
        }

        if ($context->isExecutableExtension() && $this->isRuntimeTransientPath($relative)) {
            $findings[] = new VIS_Scan_Finding(
                'EXECUTABLE_IN_TRANSIENT_PATH',
                72,
                88,
                'Executable code exists in a runtime cache, temporary, upgrade, or backup storage location.',
                false,
                VIS_Scan_Finding::CLASS_POLICY
            );
        }

        if (preg_match('~(?:^|/)(?:\.user\.ini|\.htaccess)$~', $relative) === 1) {
            $findings[] = new VIS_Scan_Finding(
                'SENSITIVE_SERVER_POLICY_FILE',
                20,
                100,
                'Sensitive server policy file is included for content-aware review.',
                false,
                VIS_Scan_Finding::CLASS_INFO
            );
        }
        return $findings;
    }

    private function isRuntimeTransientPath(string $relative): bool {
        $patterns = [
            '~^(?:cache|tmp|temp|backup|backups)/~',
            '~^wp-content/(?:cache|upgrade|upgrade-temp-backup|tmp|temp)(?:/|$)~',
            '~^wp-content/uploads/(?:cache|tmp|temp|backup|backups)(?:/|$)~',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $relative) === 1) return true;
        }
        return false;
    }

    private function isInertUploadGuard(string $path, VIS_Scan_Context $context): bool {
        if ($context->extension !== 'php' || strtolower(basename($path)) !== 'index.php') return false;
        $size = @filesize($path);
        if ($size === false || $size < 1 || $size > self::GUARD_MAX_BYTES) return false;
        $content = @file_get_contents($path, false, null, 0, self::GUARD_MAX_BYTES);
        if (!is_string($content) || $content === '') return false;

        $tokens = token_get_all($content);
        $sawExit = false;
        $sawExecutable = false;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                [$id, $text] = $token;
                if (in_array($id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
                if ($id === T_EXIT) {
                    $sawExit = true;
                    continue;
                }
                if ($id === T_STRING && strtolower($text) === 'defined') continue;
                if ($id === T_CONSTANT_ENCAPSED_STRING) {
                    $literal = trim($text, "'\"");
                    if ($literal === 'ABSPATH') continue;
                }
                if (defined('T_LOGICAL_OR') && $id === T_LOGICAL_OR) continue;
                if (defined('T_BOOLEAN_OR') && $id === T_BOOLEAN_OR) continue;
                $sawExecutable = true;
                break;
            }
            if (trim($token) === '') continue;
            if (in_array($token, ['(', ')', ';', '!', '|'], true)) continue;
            $sawExecutable = true;
            break;
        }

        // Empty/comment-only index.php ("Silence is golden") and explicit exit guards are inert.
        return !$sawExecutable && ($sawExit || trim(preg_replace('~<\?php|\?>~i', '', $content) ?? '') === '' || str_contains(strtolower($content), 'silence is golden'));
    }
}
