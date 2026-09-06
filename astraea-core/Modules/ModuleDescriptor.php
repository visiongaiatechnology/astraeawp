<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules;

/**
 * Immutable metadata descriptor for an Astraea First-Party Module.
 *
 * @package Astraea\Modules
 */
final class ModuleDescriptor {
    /**
     * @param string $id Unique machine-readable module identifier (e.g. 'gedefense', 'vault').
     * @param string $name Human-readable module name.
     * @param string $version Module semantic version.
     * @param BootPhase $bootPhase Deterministic boot phase.
     * @param array<string> $dependencies Required module IDs that must boot and be active first.
     * @param array<string> $conflicts Conflicting module IDs or plugin identifiers.
     * @param array<string> $requiredCapabilities WordPress capabilities required to administer.
     * @param string $migrationVersion Schema/migration version requirement.
     * @param string $securityEventNamespace Namespace used in Security Event Fabric.
     * @param string|null $adminRoute Administration screen slug or null.
     * @param bool $isToggleable False for critical kernel modules that cannot be disabled.
     * @param string $description Brief module summary.
     * @param array<string, mixed> $compatibilityInfo Compatibility metadata.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly BootPhase $bootPhase,
        public readonly array $dependencies = [],
        public readonly array $conflicts = [],
        public readonly array $requiredCapabilities = ['manage_options'],
        public readonly string $migrationVersion = '1.0.0',
        public readonly string $securityEventNamespace = 'Module',
        public readonly ?string $adminRoute = null,
        public readonly bool $isToggleable = true,
        public readonly string $description = '',
        public readonly array $compatibilityInfo = []
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'id'                       => $this->id,
            'name'                     => $this->name,
            'version'                  => $this->version,
            'boot_phase'               => $this->bootPhase->value,
            'dependencies'             => $this->dependencies,
            'conflicts'                => $this->conflicts,
            'required_capabilities'    => $this->requiredCapabilities,
            'migration_version'        => $this->migrationVersion,
            'security_event_namespace' => $this->securityEventNamespace,
            'admin_route'              => $this->adminRoute,
            'is_toggleable'            => $this->isToggleable,
            'description'              => $this->description,
            'compatibility_info'       => $this->compatibilityInfo,
        ];
    }
}
