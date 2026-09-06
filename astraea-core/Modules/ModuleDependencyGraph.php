<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules;

use Astraea\Exceptions\SecurityException;
use Astraea\Exceptions\ValidationException;

/**
 * Directed acyclic graph (DAG) resolver for Astraea Module Fabric.
 * Enforces fail-closed dependency resolution and cycle rejection.
 *
 * @package Astraea\Modules
 */
final class ModuleDependencyGraph {

    /**
     * Topologically sort modules ensuring dependencies execute before dependents.
     * @param array<string, ModuleInterface> $modules
     * @param string[] $satisfiedExternalDependencies List of dependency IDs already satisfied in prior boot phases.
     * @return list<ModuleInterface>
     * @throws SecurityException If a cycle or missing dependency is detected.
     */
    public static function resolve(array $modules, array $satisfiedExternalDependencies = []): array {
        $inDegree = [];
        $adjacency = []; // dep -> list of dependents

        foreach ($modules as $id => $module) {
            $inDegree[$id] = 0;
            $adjacency[$id] = [];
        }

        foreach ($modules as $id => $module) {
            $deps = $module->descriptor()->dependencies;
            foreach ($deps as $depId) {
                if (!isset($modules[$depId])) {
                    $isExternalSatisfied = in_array($depId, $satisfiedExternalDependencies, true)
                        || (class_exists(ModuleRegistry::class) && ModuleRegistry::getState($depId) === ModuleState::ACTIVE);

                    if ($isExternalSatisfied) {
                        continue;
                    }

                    throw new SecurityException(sprintf(
                        'Missing required dependency: Module "%s" depends on unregistered module "%s".',
                        $id,
                        $depId
                    ));
                }

                // $depId must come before $id, so directed edge is $depId -> $id
                $adjacency[$depId][] = $id;
                $inDegree[$id]++;
            }
        }

        // Kahn's algorithm for topological sorting
        $queue = [];
        foreach ($inDegree as $id => $degree) {
            if ($degree === 0) {
                $queue[] = $id;
            }
        }

        $sortedIds = [];
        while (!empty($queue)) {
            $currentId = array_shift($queue);
            $sortedIds[] = $currentId;

            foreach ($adjacency[$currentId] as $dependentId) {
                $inDegree[$dependentId]--;
                if ($inDegree[$dependentId] === 0) {
                    $queue[] = $dependentId;
                }
            }
        }

        if (count($sortedIds) !== count($modules)) {
            $remaining = [];
            foreach ($inDegree as $id => $deg) {
                if ($deg > 0) {
                    $remaining[] = $id;
                }
            }
            throw new SecurityException(sprintf(
                'Cyclic module dependency detected among modules: [%s]. Boot aborted.',
                implode(', ', $remaining)
            ));
        }

        $result = [];
        foreach ($sortedIds as $id) {
            $result[] = $modules[$id];
        }

        return $result;
    }

    /**
     * Validate that no conflicting modules are simultaneously active.
     *
     * @param array<string, ModuleInterface> $activeModules
     * @throws SecurityException If conflict is found.
     */
    public static function assertNoConflicts(array $activeModules): void {
        foreach ($activeModules as $id => $module) {
            $conflicts = $module->descriptor()->conflicts;
            foreach ($conflicts as $conflictId) {
                if (isset($activeModules[$conflictId])) {
                    throw new SecurityException(sprintf(
                        'Module conflict detected: Module "%s" conflicts with active module "%s".',
                        $id,
                        $conflictId
                    ));
                }
            }
        }
    }
}
