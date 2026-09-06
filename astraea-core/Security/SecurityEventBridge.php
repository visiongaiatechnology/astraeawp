<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Security;

/**
 * Bridges real subsystem events into Astraea's central evidence-only audit log.
 */
final class SecurityEventBridge {
    private static bool $initialized = false;

    public static function init(): void {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        add_action('astraea_gedefense_security_event', [self::class, 'fromGeDefense'], 10, 1);
        add_action('astraea_vault_security_event', [self::class, 'fromVault'], 10, 2);
    }

    public static function isInitialized(): bool {
        return self::$initialized;
    }

    /** @param array<string,mixed> $event */
    public static function fromGeDefense(array $event): void {
        $module = isset($event['module']) ? (string)$event['module'] : 'GeDefense';
        if (strtoupper($module) === 'ASTRAEA_VAULT') {
            return; // Vault records directly; avoid duplicate bridge events.
        }
        $severityNumber = isset($event['severity']) ? (int)$event['severity'] : 1;
        $severity = $severityNumber >= 8
            ? SecurityEventManager::SEVERITY_CRITICAL
            : ($severityNumber >= 5 ? SecurityEventManager::SEVERITY_WARNING : SecurityEventManager::SEVERITY_INFO);

        SecurityEventManager::recordOnce(
            $severity,
            'GeDefense:' . $module,
            isset($event['type']) ? (string)$event['type'] : 'event',
            isset($event['message']) ? (string)$event['message'] : 'GeDefense event recorded.',
            isset($event['context']) && is_array($event['context']) ? $event['context'] : [],
            10
        );
    }

    /** @param array<string,mixed> $context */
    public static function fromVault(string $event, array $context = []): void {
        $severity = str_contains($event, 'failed') || str_contains($event, 'corrupt')
            ? SecurityEventManager::SEVERITY_CRITICAL
            : (str_contains($event, 'rollback') || str_contains($event, 'rejected')
                ? SecurityEventManager::SEVERITY_WARNING
                : SecurityEventManager::SEVERITY_INFO);

        SecurityEventManager::recordOnce(
            $severity,
            'Vault',
            $event,
            'Astraea Vault security event.',
            $context,
            10
        );
    }
}
