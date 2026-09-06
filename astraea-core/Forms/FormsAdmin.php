<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Forms;

use Astraea\Crypto\CryptoService;
use Astraea\Crypto\KeyContext;
use Astraea\Auth\StepUpAuthService;

/**
 * Administration Screen & Encrypted Submission Viewer for Astraea Forms Light.
 *
 * @package Astraea\Forms
 */
final class FormsAdmin {

    public static function init(): void {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [self::class, 'registerAdminMenu']);
    }

    public static function registerAdminMenu(): void {
        add_submenu_page(
            'tools.php',
            'Astraea Forms Light',
            'Forms Light',
            'manage_options',
            'astraea-forms',
            [self::class, 'renderScreen']
        );
    }

    public static function renderScreen(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.', 'Forms', ['response' => 403]);
        }

        $stepUpVerified = class_exists(StepUpAuthService::class) && StepUpAuthService::isCurrentSessionVerified();
        $submissions = (array)get_option(SubmissionProcessor::OPTION_SUBMISSIONS, []);
        $submissionCount = count($submissions);
        ?>
        <div class="wrap astraea-glass-wrap" style="max-width: 1000px; margin: 24px auto;">
            <div style="margin-bottom: 24px;">
                <h1 style="font-size: 24px; font-weight: 700; color: #f8fafc; margin: 0;">Astraea Forms Light</h1>
                <p style="color: #94a3b8; font-size: 13px; margin: 4px 0 0;">Zero-bloat, secure form handling with honeypot defenses and AEAD encrypted storage.</p>
            </div>

            <!-- Available Forms Overview -->
            <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px; margin-bottom: 24px;">
                <h3 style="color: #f8fafc; margin: 0 0 14px; font-size: 15px;">Default Form Shortcode</h3>
                <p style="color: #cbd5e1; font-size: 13px; margin-bottom: 12px;">
                    Embed the secure contact form anywhere using the standard Astraea shortcode:
                </p>
                <div style="background: rgba(0,0,0,0.5); padding: 12px 16px; border-radius: 6px; font-family: monospace; font-size: 13px; color: #38bdf8; display: inline-block;">
                    [astraea_form id="contact"]
                </div>
            </div>

            <!-- Encrypted Submissions Box -->
            <div style="background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <div>
                        <h3 style="color: #f8fafc; margin: 0; font-size: 15px;">Encrypted Submissions Vault (<?php echo (int)$submissionCount; ?>)</h3>
                        <p style="color: #94a3b8; font-size: 12px; margin: 2px 0 0;">Submissions are encrypted at rest using Sodium AEAD under dedicated cryptographic domain separation.</p>
                    </div>
                    <?php if (!$stepUpVerified): ?>
                        <a href="<?php echo esc_url(StepUpAuthService::getVerificationUrl(admin_url('tools.php?page=astraea-forms'))); ?>" class="button button-primary" style="background: #0284c7; border: 1px solid #38bdf8; font-size: 12px; border-radius: 6px;">
                            Authenticate Step-Up to Decrypt
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!$stepUpVerified): ?>
                    <div style="background: rgba(30, 41, 59, 0.5); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 8px; padding: 24px; text-align: center; color: #94a3b8; font-size: 13px;">
                        🔒 Submissions are protected by session Step-Up authentication. Complete Step-Up to unlock and view decrypted payloads.
                    </div>
                <?php else: ?>
                    <?php if (empty($submissions)): ?>
                        <p style="color: #64748b; font-size: 13px;">No form submissions currently stored.</p>
                    <?php else: ?>
                        <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13px; color: #cbd5e1;">
                            <thead>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.08); color: #94a3b8; font-size: 11px; text-transform: uppercase;">
                                    <th style="padding: 10px 12px;">ID</th>
                                    <th style="padding: 10px 12px;">Form</th>
                                    <th style="padding: 10px 12px;">Date</th>
                                    <th style="padding: 10px 12px;">Decrypted Payload</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_reverse($submissions) as $entry):
                                    $decryptedText = 'Decryption error';
                                    try {
                                        $envelope = (string)($entry['envelope'] ?? '');
                                        $formId = (string)($entry['form_id'] ?? '');
                                        $aad = 'form-submission:' . $formId;
                                        $rawJson = CryptoService::decrypt($envelope, KeyContext::FORMS_SUBMISSION, $aad);
                                        $parsed = json_decode($rawJson, true);
                                        if (is_array($parsed)) {
                                            $decryptedText = '';
                                            foreach ($parsed as $k => $v) {
                                                $decryptedText .= esc_html($k) . ': ' . esc_html((string)$v) . ' | ';
                                            }
                                            $decryptedText = rtrim($decryptedText, ' | ');
                                        }
                                    } catch (\Throwable $e) {
                                        $decryptedText = 'Unable to decrypt (Key rotation or invalid envelope)';
                                    }
                                ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                                    <td style="padding: 10px 12px; font-family: monospace; font-size: 11px; color: #64748b;"><?php echo esc_html(substr((string)($entry['id'] ?? ''), 0, 8)); ?></td>
                                    <td style="padding: 10px 12px; font-weight: 600; color: #38bdf8;"><?php echo esc_html((string)($entry['form_id'] ?? '')); ?></td>
                                    <td style="padding: 10px 12px; font-size: 12px; color: #94a3b8;"><?php echo esc_html(date_i18n('Y-m-d H:i', (int)($entry['timestamp'] ?? 0))); ?></td>
                                    <td style="padding: 10px 12px; font-size: 12px; color: #f1f5f9;"><?php echo esc_html($decryptedText); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
