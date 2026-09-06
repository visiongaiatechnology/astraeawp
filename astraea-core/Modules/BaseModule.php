<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules;

use Astraea\Exceptions\ValidationException;

/**
 * Abstract Base Class for Astraea First-Party Modules.
 *
 * Implements common configuration persistence and status handling.
 *
 * @package Astraea\Modules
 */
abstract class BaseModule implements ModuleInterface {

    /**
     * Determine if module is enabled.
     */
    public function isEnabled(): bool {
        if (!$this->descriptor()->isToggleable) {
            return true;
        }

        if (function_exists('get_option')) {
            $opt = get_option('astraea_module_' . $this->descriptor()->id . '_enabled', '1');
            return $opt === '1' || $opt === 1 || $opt === true;
        }

        return true;
    }

    /**
     * Hook called when module is enabled via administrative action.
     */
    public function onEnable(): void {
        if (function_exists('update_option')) {
            update_option('astraea_module_' . $this->descriptor()->id . '_enabled', '1');
        }
    }

    /**
     * Hook called when module is disabled via administrative action.
     */
    public function onDisable(): void {
        if (!$this->descriptor()->isToggleable) {
            throw new ValidationException('Cannot disable critical kernel module: ' . $this->descriptor()->id);
        }

        if (function_exists('update_option')) {
            update_option('astraea_module_' . $this->descriptor()->id . '_enabled', '0');
        }
    }
}
