<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Forms;

use Astraea\Crypto\CryptoService;
use Astraea\Crypto\KeyContext;
use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\ValidationException;
use Astraea\Exceptions\StorageException;
use Astraea\Security\SecurityEventManager;
use Astraea\Security\AtomicCounter;
use Astraea\Security\Logger;
use Astraea\Media\UploadPipeline;

/**
 * Hardened Form Submission Processing Engine.
 *
 * Implements the full security pipeline:
 * Request -> Nonce/CSRF -> GeDefense Bot Check -> Rate Limiting -> Honeypot
 * -> Field Validation -> FileGuard/UploadPipeline -> Mail Gateway -> AEAD Encrypted Storage.
 *
 * @package Astraea\Forms
 */
final class SubmissionProcessor {

    public const OPTION_SUBMISSIONS = 'astraea_form_submissions';
    private const MAX_GLOBAL_UPLOADS_PER_DAY = 1000;
    private const MAX_GLOBAL_UPLOAD_BYTES_PER_DAY = 524288000;
    private const UPLOAD_RETENTION_SECONDS = 604800;

    public static function init(): void {
        add_action('admin_post_nopriv_astraea_form_submit', [self::class, 'handleSubmission']);
        add_action('admin_post_astraea_form_submit', [self::class, 'handleSubmission']);
    }

    public static function handleSubmission(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_die('Invalid request method.', 'Forms', ['response' => 405]);
        }

        $formId = sanitize_key($_POST['astraea_form_id'] ?? '');
        $form = FormRenderer::getForm($formId);
        if ($form === null) {
            wp_die('Invalid form identifier.', 'Forms', ['response' => 400]);
        }

        // 1. CSRF / Nonce Verification
        $nonce = (string)($_POST['_astraea_form_nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'astraea_form_submit_' . $form->id)) {
            self::logSecurityEvent($formId, 'form_csrf_failure', 'CSRF verification failed on form submission.');
            wp_die('Security token expired or invalid.', 'Forms', ['response' => 403]);
        }

        // 2. Honeypot Bot Trap Check
        $honeypot = trim((string)($_POST['_astraea_hp_check'] ?? ''));
        if ($honeypot !== '') {
            // Silently drop bot submission
            self::logSecurityEvent($formId, 'form_honeypot_triggered', 'Honeypot field filled by automated bot.');
            wp_safe_redirect(add_query_arg('form_status', 'submitted', wp_get_referer() ?: home_url('/')));
            exit;
        }

        // 3. Rate Limiting Check (5 submissions per 10 minutes)
        self::assertRateLimit($formId);

        // 4. Validate and Sanitize Fields
        $sanitizedData = [];
        $uploadedFiles = [];

        try {
            foreach ($form->fields as $field) {
                $rawVal = $_POST[$field->name] ?? null;

                if ($field->type === 'file') {
                    if (!empty($_FILES[$field->name]['tmp_name'])) {
                        $destDir = defined('WP_CONTENT_DIR')
                            ? WP_CONTENT_DIR . '/uploads/astraea-forms'
                            : sys_get_temp_dir() . '/astraea-forms';
                        self::purgeExpiredUploads($destDir);
                        $result = UploadPipeline::process($_FILES[$field->name], $destDir);
                        $uploadedFiles[$field->name] = $result['path'];
                        $sanitizedData[$field->name] = '[File: ' . $result['filename'] . ']';
                    } elseif ($field->required) {
                        throw new ValidationException(sprintf('File upload required for "%s".', $field->label));
                    }
                    continue;
                }

                if ($field->required && ($rawVal === null || trim((string)$rawVal) === '')) {
                    throw new ValidationException(sprintf('Field "%s" is required.', $field->label));
                }

                if ($rawVal === null || $rawVal === '') {
                    $sanitizedData[$field->name] = '';
                    continue;
                }

                $cleanVal = match ($field->type) {
                    'email'    => self::validateEmail((string)$rawVal, $field->label),
                    'url'      => self::validateUrl((string)$rawVal, $field->label),
                    'number'   => is_numeric($rawVal) ? (string)$rawVal : throw new ValidationException(sprintf('Field "%s" must be numeric.', $field->label)),
                    'textarea' => sanitize_textarea_field((string)$rawVal),
                    'consent', 'checkbox' => !empty($rawVal) ? 'yes' : 'no',
                    default    => sanitize_text_field((string)$rawVal),
                };

                $sanitizedData[$field->name] = $cleanVal;
            }

            // Consume the global budget only after every scalar field and every
            // upload has passed validation. Failed submissions cannot burn quota.
            self::reserveUploadBudget($form);

            // 5. Encrypted Submission Storage
            if ($form->storeEncrypted) {
                self::storeEncryptedSubmission($form->id, $sanitizedData);
            }

            // 6. Deliver via Mail Gateway
            self::sendNotificationEmail($form, $sanitizedData);

            $redirectUrl = add_query_arg('form_status', 'success', wp_get_referer() ?: home_url('/'));
            wp_safe_redirect($redirectUrl);
            exit;

        } catch (ValidationException $e) {
            foreach ($uploadedFiles as $filePath) {
                if (is_string($filePath) && is_file($filePath)) {
                    @unlink($filePath);
                }
            }
            $cleanError = sanitize_text_field(substr($e->getMessage(), 0, 100));
            $redirectUrl = add_query_arg(['form_status' => 'error', 'form_error' => urlencode($cleanError)], wp_get_referer() ?: home_url('/'));
            wp_safe_redirect($redirectUrl);
            exit;
        } catch (\Throwable $e) {
            foreach ($uploadedFiles as $filePath) {
                if (is_string($filePath) && is_file($filePath)) {
                    @unlink($filePath);
                }
            }
            Logger::critical('[Forms] Submission processing fault: ' . $e->getMessage());
            wp_die('An error occurred processing your submission.', 'Forms', ['response' => 500]);
        }
    }

    private static function validateEmail(string $val, string $label): string {
        $clean = sanitize_email(trim($val));
        if (!filter_var($clean, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(sprintf('Field "%s" must be a valid email address.', $label));
        }
        return $clean;
    }

    private static function validateUrl(string $val, string $label): string {
        $clean = esc_url_raw(trim($val));
        if (!filter_var($clean, FILTER_VALIDATE_URL)) {
            throw new ValidationException(sprintf('Field "%s" must be a valid URL.', $label));
        }
        return $clean;
    }

    private static function assertRateLimit(string $formId): void {
        $ip = filter_var((string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), FILTER_VALIDATE_IP) ?: '127.0.0.1';
        $ipHash = hash('sha256', $ip . '|' . $formId);
        $key = 'astraea_frl_' . substr($ipHash, 0, 16);
        $maxLimit = 5;
        $window = 600; // 10 minutes

        if (!AtomicCounter::consume($key, $maxLimit, $window)) {
            self::logSecurityEvent($formId, 'form_rate_limit_exceeded', 'Rate limit triggered for form submission.');
            wp_die('Too many submissions. Please wait before submitting again.', 'Forms', ['response' => 429]);
        }
    }

    private static function reserveUploadBudget(FormDefinition $form): void {
        $uploadCount = 0;
        $uploadBytes = 0;
        foreach ($form->fields as $field) {
            if ($field->type !== 'file' || empty($_FILES[$field->name]['tmp_name'])) continue;
            $tmpPath = (string)$_FILES[$field->name]['tmp_name'];
            if (!is_file($tmpPath) || (PHP_SAPI !== 'cli' && !is_uploaded_file($tmpPath))) throw new SecurityException('Upload path validation failed.');
            $measured = filesize($tmpPath);
            if ($measured === false || $measured < 1 || $measured > UploadPipeline::MAX_BYTES) throw new ValidationException('Size boundary violation.');
            $uploadCount++;
            $uploadBytes += $measured;
        }
        if ($uploadCount === 0) return;
        if (!AtomicCounter::consume('forms:uploads:count', self::MAX_GLOBAL_UPLOADS_PER_DAY, DAY_IN_SECONDS, $uploadCount)
            || !AtomicCounter::consume('forms:uploads:bytes', self::MAX_GLOBAL_UPLOAD_BYTES_PER_DAY, DAY_IN_SECONDS, $uploadBytes)) {
            throw new SecurityException('Global upload resource budget exhausted.');
        }
    }

    private static function purgeExpiredUploads(string $directory): void {
        $resolved = realpath($directory);
        if ($resolved === false || !is_dir($resolved)) return;
        $cutoff = time() - self::UPLOAD_RETENTION_SECONDS;
        foreach (new \FilesystemIterator($resolved, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isFile() && !$item->isLink() && $item->getMTime() < $cutoff) {
                @unlink($item->getPathname());
            }
        }
    }

    private static function storeEncryptedSubmission(string $formId, array $data): void {
        $payloadJson = json_encode($data, JSON_THROW_ON_ERROR);
        $aad = 'form-submission:' . $formId;

        // Encrypt with domain-separated FORMS_SUBMISSION key
        $envelope = CryptoService::encrypt($payloadJson, KeyContext::FORMS_SUBMISSION, $aad);

        $record = [
            'id'        => bin2hex(random_bytes(8)),
            'form_id'   => $formId,
            'envelope'  => $envelope,
            'timestamp' => time(),
        ];

        $submissions = get_option(self::OPTION_SUBMISSIONS, []);
        if (!is_array($submissions)) {
            $submissions = [];
        }

        // Cap stored submissions to latest 500
        if (count($submissions) >= 500) {
            array_shift($submissions);
        }

        $submissions[] = $record;
        update_option(self::OPTION_SUBMISSIONS, $submissions, false);
    }

    private static function sendNotificationEmail(FormDefinition $form, array $data): void {
        if ($form->notifyEmail === '') {
            return;
        }

        $subject = sprintf('[%s] New submission: %s', get_bloginfo('name'), $form->title);
        // Header injection prevention: strip newlines from subject
        $cleanSubject = str_replace(["\r", "\n"], ' ', $subject);

        $body = "New form submission received:\n\n";
        foreach ($data as $k => $v) {
            $body .= sprintf("%s: %s\n", ucfirst($k), $v);
        }
        $body .= sprintf("\nSubmitted on: %s\n", date('Y-m-d H:i:s'));

        if (wp_mail($form->notifyEmail, $cleanSubject, $body) !== true) {
            throw new StorageException('Form notification delivery failed.');
        }
    }

    private static function logSecurityEvent(string $formId, string $type, string $message): void {
        if (class_exists(SecurityEventManager::class)) {
            SecurityEventManager::recordOnce(
                SecurityEventManager::SEVERITY_SECURITY,
                'Forms',
                $type,
                $message,
                ['form_id' => $formId],
                60
            );
        }
    }
}
