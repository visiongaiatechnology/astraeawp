<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules\CoreModules;

use Astraea\Exceptions\SecurityException;
use Astraea\Modules\BaseModule;
use Astraea\Modules\BootPhase;
use Astraea\Modules\ModuleDescriptor;
use Astraea\Modules\ModuleHealth;
use Astraea\Media\MediaAdmin;
use Astraea\Media\FormatCapabilities;
use Astraea\Media\SvgSanitizer;

/**
 * Astraea Media Engine First-Party Module Adapter.
 *
 * @package Astraea\Modules\CoreModules
 */
final class MediaModule extends BaseModule {

    public function descriptor(): ModuleDescriptor {
        return new ModuleDescriptor(
            id: 'media',
            name: 'Astraea Media Engine',
            version: '1.0.0',
            bootPhase: BootPhase::PHASE_E,
            dependencies: [],
            conflicts: ['safe-svg', 'svg-support', 'webp-express', 'ewww-image-optimizer'],
            requiredCapabilities: ['upload_files'],
            migrationVersion: '1.0.0',
            securityEventNamespace: 'Media',
            adminRoute: 'astraea-media',
            isToggleable: true,
            description: 'Hardened upload security pipeline, XML SVG sanitizer, EXIF stripping, and WebP/AVIF capability engine.',
            compatibilityInfo: ['svg_sanitizer' => true]
        );
    }

    public function boot(): void {
        if (!extension_loaded('dom') || !class_exists(\DOMDocument::class)) {
            throw new SecurityException('Media module blocked because the DOM/XML security dependency is unavailable.');
        }

        MediaAdmin::init();

        if (function_exists('add_filter')) {
            add_filter('wp_handle_upload_prefilter', static function(array $file): array {
                $name = $file['name'] ?? '';
                $tmp = $file['tmp_name'] ?? '';

                // Handle SVG upload sanitization
                if (str_ends_with(strtolower($name), '.svg') && is_file($tmp)) {
                    $raw = file_get_contents($tmp);
                    if (is_string($raw)) {
                        $sanitized = SvgSanitizer::sanitize($raw);
                        file_put_contents($tmp, $sanitized);
                    }
                }
                return $file;
            });
        }
    }

    public function probeHealth(): ModuleHealth {
        $start = hrtime(true);

        if (!extension_loaded('dom') || !class_exists(\DOMDocument::class)) {
            return ModuleHealth::critical(
                'Astraea Media Engine',
                'DOM/XML unavailable; SVG processing is blocked.',
                ['dom_xml' => false],
                (hrtime(true) - $start) / 1e6
            );
        }

        $matrix = FormatCapabilities::matrix();
        $latency = (hrtime(true) - $start) / 1e6;

        $hasWebp = $matrix['webp_supported'] ?? false;
        $hasAvif = $matrix['avif_supported'] ?? false;

        return ModuleHealth::healthy(
            'Astraea Media Engine',
            sprintf('Media engine active. Codecs: WebP=%s, AVIF=%s.', $hasWebp ? 'Yes' : 'No', $hasAvif ? 'Yes' : 'No'),
            ['webp' => $hasWebp, 'avif' => $hasAvif],
            $latency
        );
    }
}
