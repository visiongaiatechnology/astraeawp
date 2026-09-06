<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules;

use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\ValidationException;
use Astraea\Exceptions\StorageException;

/**
 * Thread-safe Registry and State Manager for Astraea First-Party Modules.
 *
 * @package Astraea\Modules
 */
final class ModuleRegistry {

    /** @var array<string, ModuleInterface> */
    private static array $modules = [];

    /** @var array<string, ModuleState> */
    private static array $states = [];

    /** @var array<string, string> */
    private static array $reasons = [];

    /** @var array<string, bool> */
    private static array $enabledOverrides = [];

    private static bool $initialized = false;

    /**
     * Register a module instance.
     */
    public static function register(ModuleInterface $module): void {
        $desc = $module->descriptor();
        $id = strtolower(trim($desc->id));

        if ($id === '') {
            throw new ValidationException('Cannot register module with empty identifier.');
        }

        self::$modules[$id] = $module;
        if (!isset(self::$states[$id])) {
            self::$states[$id] = ModuleState::UNBOOTED;
        }
    }

    /**
     * Retrieve module by ID.
     */
    public static function get(string $id): ?ModuleInterface {
        $key = strtolower(trim($id));
        return self::$modules[$key] ?? null;
    }

    /**
     * Get all registered modules.
     *
     * @return array<string, ModuleInterface>
     */
    public static function getAll(): array {
        return self::$modules;
    }

    /**
     * Get current runtime state of a module.
     */
    public static function getState(string $id): ModuleState {
        $key = strtolower(trim($id));
        return self::$states[$key] ?? ModuleState::UNBOOTED;
    }

    /**
     * Set runtime state and optional diagnostic message.
     */
    public static function setState(string $id, ModuleState $state, ?string $reason = null): void {
        $key = strtolower(trim($id));
        self::$states[$key] = $state;
        if ($reason !== null) {
            self::$reasons[$key] = $reason;
        } elseif ($state === ModuleState::ACTIVE) {
            unset(self::$reasons[$key]);
        }
    }

    /**
     * Get last error or status explanation.
     */
    public static function getLastError(string $id): ?string {
        $key = strtolower(trim($id));
        return self::$reasons[$key] ?? null;
    }

    /**
     * Check if module is enabled by configuration or override.
     */
    public static function isModuleEnabled(string $id): bool {
        $key = strtolower(trim($id));
        if (isset(self::$enabledOverrides[$key])) {
            return self::$enabledOverrides[$key];
        }

        $module = self::get($key);
        if ($module === null) {
            return false;
        }

        if (!$module->descriptor()->isToggleable) {
            return true; // Critical kernel components cannot be disabled
        }

        if (function_exists('get_option')) {
            $states = get_option('astraea_module_states', []);
            if (is_array($states) && array_key_exists($key, $states)) {
                return (bool)$states[$key];
            }
        }

        return $module->isEnabled();
    }

    /**
     * Persistently set module enabled state.
     */
    public static function setModuleEnabled(string $id, bool $enabled): void {
        $key = strtolower(trim($id));
        $module = self::get($key);
        if ($module === null) {
            throw new ValidationException(sprintf('Unknown module "%s".', $id));
        }

        if (!$module->descriptor()->isToggleable && !$enabled) {
            throw new SecurityException(sprintf(
                'Module "%s" is a mandatory kernel component and cannot be disabled.',
                $module->descriptor()->name
            ));
        }

        self::$enabledOverrides[$key] = $enabled;

        if (function_exists('update_option') && function_exists('get_option')) {
            $states = get_option('astraea_module_states', []);
            if (!is_array($states)) {
                $states = [];
            }
            $states[$key] = $enabled;
            if (!update_option('astraea_module_states', $states)) {
                throw new StorageException('Failed to persist module state option.');
            }
        }

        if ($enabled) {
            $module->onEnable();
        } else {
            $module->onDisable();
            self::setState($key, ModuleState::DISABLED, 'Disabled by administrator');
        }
    }

    /**
     * Resolve and sort modules for a specific boot phase.
     *
     * @return list<ModuleInterface>
     */
    public static function getBootableModulesForPhase(BootPhase $phase): array {
        $phaseModules = [];
        foreach (self::$modules as $id => $module) {
            if ($module->descriptor()->bootPhase === $phase) {
                if (self::isModuleEnabled($id)) {
                    $phaseModules[$id] = $module;
                } else {
                    self::setState($id, ModuleState::DISABLED, 'Module disabled by policy');
                }
            }
        }

        ModuleDependencyGraph::assertNoConflicts($phaseModules);

        // Filter and check dependencies against already active or within phase
        $resolvable = [];
        foreach ($phaseModules as $id => $module) {
            $deps = $module->descriptor()->dependencies;
            $depsSatisfied = true;
            $missingDepName = '';

            foreach ($deps as $depId) {
                $depState = self::getState($depId);
                $isDepInPhase = isset($phaseModules[$depId]);

                if (!$isDepInPhase && $depState !== ModuleState::ACTIVE) {
                    $depsSatisfied = false;
                    $missingDepName = $depId;
                    break;
                }
            }

            if (!$depsSatisfied) {
                // FAIL-CLOSED: Block module boot, do not silently degrade
                self::setState(
                    $id,
                    ModuleState::BLOCKED,
                    sprintf('Required dependency "%s" is not active.', $missingDepName)
                );
                continue;
            }

            $resolvable[$id] = $module;
        }

        $activeExternalDeps = [];
        foreach (self::$states as $depId => $state) {
            if ($state === ModuleState::ACTIVE) {
                $activeExternalDeps[] = $depId;
            }
        }

        return ModuleDependencyGraph::resolve($resolvable, $activeExternalDeps);
    }

    /**
     * Reset registry state (for testing).
     */
    public static function reset(): void {
        self::$modules = [];
        self::$states = [];
        self::$reasons = [];
        self::$enabledOverrides = [];
        self::$initialized = false;
    }
}
