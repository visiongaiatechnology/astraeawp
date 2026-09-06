<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Notification;

use Astraea\Vault\Config;

final class Notifier
{
    private const OPTION_NOTICES = 'astraea_vault_notices';
    private const OPTION_DEFERRED_MAIL = 'astraea_vault_deferred_mail';

    public function recovery(array $data): void
    {
        $plugin = sanitize_text_field((string)($data['plugin'] ?? 'Unknown plugin'));
        $previous = sanitize_text_field((string)($data['previous_version'] ?? 'unknown'));
        $failed = sanitize_text_field((string)($data['new_version'] ?? 'unknown'));
        $error = wp_strip_all_tags((string)($data['error_message'] ?? 'Critical plugin error'));
        $file = wp_strip_all_tags((string)($data['error_file'] ?? 'unknown'));
        $line = max(0, (int)($data['error_line'] ?? 0));
        $status = sanitize_text_field((string)($data['recovery_result'] ?? 'completed'));

        $message = sprintf(
            "Astraea Vault recovery\n\nPlugin: %s\nUpdate: %s → %s\nDetected: %s\nLocation: %s:%d\nRecovery: %s\n",
            $plugin, $previous, $failed, $error, $file, $line, $status
        );
        $this->pushNotice([
            'type' => $status === 'rolled_back' ? 'success' : 'error',
            'title' => 'Astraea Vault Recovery',
            'message' => $message,
            'created_at' => time(),
        ]);

        $settings = Config::settings();
        if (!empty($settings['email_notifications'])) {
            $email = get_option('admin_email');
            if (is_string($email) && is_email($email)) {
                if (function_exists('wp_mail')) {
                    wp_mail($email, '[Astraea Vault] Recovery event', $message);
                } else {
                    $this->queueMail($email, '[Astraea Vault] Recovery event', $message);
                }
            }
        }
    }

    public function flushDeferredMail(): void
    {
        if (!function_exists('wp_mail')) {
            return;
        }
        $queue = get_option(self::OPTION_DEFERRED_MAIL, []);
        if (!is_array($queue) || $queue === []) {
            return;
        }

        // Delete first to guarantee at-most-once retry semantics across request crashes.
        // A failed mail remains represented by the persistent local Vault notice.
        delete_option(self::OPTION_DEFERRED_MAIL);
        foreach (array_slice($queue, 0, 20) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $email = isset($item['email']) && is_string($item['email']) ? $item['email'] : '';
            $subject = isset($item['subject']) && is_string($item['subject']) ? $item['subject'] : '';
            $message = isset($item['message']) && is_string($item['message']) ? $item['message'] : '';
            if (!is_email($email) || $subject === '' || $message === '') {
                continue;
            }
            wp_mail($email, $subject, $message);
        }
    }

    public function info(string $title, string $message, string $type = 'info'): void
    {
        $this->pushNotice([
            'type' => in_array($type, ['success','warning','error','info'], true) ? $type : 'info',
            'title' => sanitize_text_field($title),
            'message' => wp_strip_all_tags($message),
            'created_at' => time(),
        ]);
    }

    public function notices(): array
    {
        $items = get_option(self::OPTION_NOTICES, []);
        return is_array($items) ? array_slice(array_reverse($items), 0, 10) : [];
    }

    public function clear(): void
    {
        delete_option(self::OPTION_NOTICES);
    }

    private function queueMail(string $email, string $subject, string $message): void
    {
        $items = get_option(self::OPTION_DEFERRED_MAIL, []);
        if (!is_array($items)) {
            $items = [];
        }
        $items[] = [
            'email' => $email,
            'subject' => sanitize_text_field($subject),
            'message' => wp_strip_all_tags($message),
            'created_at' => time(),
        ];
        update_option(self::OPTION_DEFERRED_MAIL, array_slice($items, -20), false);
    }

    private function pushNotice(array $notice): void
    {
        $items = get_option(self::OPTION_NOTICES, []);
        if (!is_array($items)) { $items = []; }
        $items[] = $notice;
        if (count($items) > 50) {
            $items = array_slice($items, -50);
        }
        update_option(self::OPTION_NOTICES, $items, false);
    }
}
