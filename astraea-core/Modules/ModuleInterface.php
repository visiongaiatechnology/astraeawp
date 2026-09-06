<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules;

/**
 * Standard contract for all Astraea First-Party Modules.
 *
 * @package Astraea\Modules
 */
interface ModuleInterface {
    /**
     * Get module metadata descriptor.
     */
    public function descriptor(): ModuleDescriptor;

    /**
     * Execute module boot routine during its declared boot phase.
     * Must throw AppException / SecurityException on unrecoverable failure.
     */
    public function boot(): void;

    /**
     * Probe module health using live evidence.
     */
    public function probeHealth(): ModuleHealth;

    /**
     * Determine if the module is enabled by configuration.
     */
    public function isEnabled(): bool;

    /**
     * Hook called when module is enabled via administrative action.
     */
    public function onEnable(): void;

    /**
     * Hook called when module is disabled via administrative action.
     */
    public function onDisable(): void;
}
