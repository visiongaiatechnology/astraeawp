<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules;

use Astraea\Exceptions\AppException;
use Astraea\Exceptions\SecurityException;
use Astraea\Security\SecurityEventManager;
use Astraea\Security\Logger;

/**
 * Phased Boot Dispatcher for Astraea Module Fabric.
 * Integrates with BootOrchestrator to boot modules strictly in topological order.
 *
 * @package Astraea\Modules
 */
final class ModuleBootstrap {

    private static bool $registeredDefaults = false;

    /**
     * Register all first-party core modules.
     */
    public static function registerCoreModules(): void {
        if (self::$registeredDefaults) {
            return;
        }

        ModuleRegistry::register(new CoreModules\RecoveryModule());
        ModuleRegistry::register(new CoreModules\GeDefenseModule());
        ModuleRegistry::register(new CoreModules\VaultModule());
        ModuleRegistry::register(new CoreModules\VLPModule());
        ModuleRegistry::register(new CoreModules\MailModule());
        ModuleRegistry::register(new CoreModules\PerformanceModule());
        ModuleRegistry::register(new CoreModules\MediaModule());
        ModuleRegistry::register(new CoreModules\RedirectsModule());
        ModuleRegistry::register(new CoreModules\SeoModule());
        ModuleRegistry::register(new CoreModules\FormsModule());
        ModuleRegistry::register(new CoreModules\TasksModule());
        ModuleRegistry::register(new CoreModules\DatabaseModule());
        ModuleRegistry::register(new CoreModules\MaintenanceModule());
        ModuleRegistry::register(new CoreModules\IdentityModule());
        ModuleRegistry::register(new CoreModules\UpdateModule());
        ModuleRegistry::register(new CoreModules\CompatibilityModule());
        ModuleRegistry::register(new CoreModules\MigrationModule());

        self::$registeredDefaults = true;
    }

    /**
     * Boot modules declared for the given BootPhase.
     */
    public static function bootPhase(BootPhase $phase): void {
        self::registerCoreModules();

        try {
            $modulesToBoot = ModuleRegistry::getBootableModulesForPhase($phase);
        } catch (\Throwable $e) {
            self::logModuleError('ModuleFabric', 'Phase resolution failure for ' . $phase->value, $e);
            throw $e;
        }

        foreach ($modulesToBoot as $module) {
            $id = $module->descriptor()->id;
            ModuleRegistry::setState($id, ModuleState::BOOTING);

            try {
                $module->boot();
                ModuleRegistry::setState($id, ModuleState::ACTIVE);
            } catch (\Throwable $e) {
                $msg = sprintf('Module "%s" failed boot: %s', $id, $e->getMessage());
                ModuleRegistry::setState($id, ModuleState::FAILED, $msg);
                self::logModuleError($module->descriptor()->securityEventNamespace, $msg, $e);

                if (!$module->descriptor()->isToggleable) {
                    // Critical kernel modules fail closed and abort boot
                    throw new SecurityException('Critical kernel module failed boot: ' . $id, 0, $e);
                }
            }
        }
    }

    private static function logModuleError(string $namespace, string $message, \Throwable $e): void {
        if (class_exists(SecurityEventManager::class)) {
            SecurityEventManager::recordOnce(
                SecurityEventManager::SEVERITY_CRITICAL,
                $namespace,
                'module_boot_error',
                $message,
                ['exception' => get_class($e), 'file' => $e->getFile(), 'line' => $e->getLine()],
                300
            );
        }
        if (class_exists(Logger::class)) {
            Logger::critical(sprintf('[%s] %s', $namespace, $message), ['exception' => get_class($e)]);
        }
    }
}
