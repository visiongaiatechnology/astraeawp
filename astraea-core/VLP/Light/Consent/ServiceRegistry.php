<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\VLP\Light\Consent;

use Astraea\Exceptions\ValidationException;
use Astraea\VLP\Light\Settings;

final class ServiceRegistry {
    /** @return array<string,array<string,mixed>> */
    public static function all(): array {
        $stored = get_option(Settings::OPTION_SERVICES, []);
        $services = is_array($stored) ? $stored : [];
        foreach (self::defaults() as $id => $default) {
            if (!isset($services[$id]) || !is_array($services[$id])) {
                $services[$id] = $default;
            }
        }
        ksort($services, SORT_STRING);
        return $services;
    }

    /** @return array<string,array<string,mixed>> */
    public static function defaults(): array {
        return [
            'vgt-dattrack' => self::service('vgt-dattrack','VGT Dattrack','VisionGaiaTechnology','Lokale, verschlüsselte Reichweiten- und Performance-Statistik.','statistics',[],['vgt-dattrack','astraea_vlp_dattrack'],[],true,true),
            'google-analytics' => self::service('google-analytics','Google Analytics','Google','Reichweiten- und Nutzungsanalyse.','statistics',['google-analytics.com','googletagmanager.com'],['gtag(','GoogleAnalyticsObject','google-analytics.com'],['_ga','_gid'],true,false),
            'google-tag-manager' => self::service('google-tag-manager','Google Tag Manager','Google','Tag- und Script-Verwaltung.','marketing',['googletagmanager.com'],['GTM-','googletagmanager.com'],['_gcl_'],true,false),
            'meta-pixel' => self::service('meta-pixel','Meta Pixel','Meta','Marketing- und Conversion-Messung.','marketing',['connect.facebook.net','facebook.com'],['fbq(','fbevents.js'],['_fbp','fr'],true,false),
            'youtube' => self::service('youtube','YouTube','Google','Externe Video-Inhalte.','external_media',['youtube.com','youtube-nocookie.com','ytimg.com'],['youtube.com/embed','youtube-nocookie.com'],[],true,false),
            'vimeo' => self::service('vimeo','Vimeo','Vimeo','Externe Video-Inhalte.','external_media',['player.vimeo.com','vimeo.com'],['player.vimeo.com'],[],true,false),
            'google-maps' => self::service('google-maps','Google Maps','Google','Externe Karten-Inhalte.','external_media',['maps.googleapis.com','maps.google.com','google.com'],['maps.googleapis.com','google.com/maps'],['NID'],true,false),
            'recaptcha' => self::service('recaptcha','Google reCAPTCHA','Google','Bot- und Missbrauchsschutz.','functional',['google.com','gstatic.com','recaptcha.net'],['grecaptcha','recaptcha/api.js'],['NID'],true,false),
            'hotjar' => self::service('hotjar','Hotjar','Hotjar','Verhaltens- und Sessionanalyse.','statistics',['hotjar.com','hotjar.io'],['hj(','hotjar.com'],['_hj'],true,false),
            'clarity' => self::service('clarity','Microsoft Clarity','Microsoft','Verhaltensanalyse.','statistics',['clarity.ms'],['clarity(','clarity.ms'],['_clck','_clsk'],true,false),
            'matomo' => self::service('matomo','Matomo','Site Owner','Webanalyse.','statistics',[],['_paq.push','matomo.php','piwik.php'],['_pk_'],true,false),
        ];
    }

    /** @return array<string,mixed> */
    private static function service(string $id,string $name,string $provider,string $purpose,string $category,array $hosts,array $markers,array $cookies,bool $active,bool $internal): array {
        return compact('id','name','provider','purpose','category','hosts','markers','cookies','active','internal');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function normalize(array $input): array {
        $id = isset($input['id']) ? sanitize_key((string)$input['id']) : '';
        if ($id === '' || strlen($id) > 80) { throw new ValidationException('Service ID is invalid.'); }
        $category = isset($input['category']) ? sanitize_key((string)$input['category']) : '';
        if (!in_array($category, Settings::CATEGORIES, true)) { throw new ValidationException('Service category is invalid.'); }
        $name = sanitize_text_field((string)($input['name'] ?? ''));
        $provider = sanitize_text_field((string)($input['provider'] ?? ''));
        $purpose = sanitize_textarea_field((string)($input['purpose'] ?? ''));
        if ($name === '' || $provider === '') { throw new ValidationException('Service name and provider are required.'); }
        return [
            'id'=>$id,'name'=>mb_substr($name,0,120),'provider'=>mb_substr($provider,0,120),'purpose'=>mb_substr($purpose,0,500),
            'category'=>$category,
            'hosts'=>self::normalizeList($input['hosts'] ?? '', 'host'),
            'markers'=>self::normalizeList($input['markers'] ?? '', 'marker'),
            'cookies'=>self::normalizeList($input['cookies'] ?? '', 'cookie'),
            'active'=>!empty($input['active']),
            'internal'=>!empty($input['internal']),
        ];
    }

    /** @return string[] */
    private static function normalizeList(mixed $value, string $mode): array {
        $raw = is_array($value) ? $value : preg_split('/[\r\n,]+/', (string)$value);
        if (!is_array($raw)) { return []; }
        $out=[];
        foreach ($raw as $item) {
            $item=trim((string)$item);
            if ($item==='') continue;
            if ($mode==='host') {
                $item=strtolower($item);
                $item=preg_replace('/^https?:\/\//','',$item) ?? $item;
                $item=explode('/',$item,2)[0];
                if (preg_match('/^(?:[a-z0-9-]+\.)*[a-z0-9-]+\.[a-z]{2,63}$/D',$item)!==1) continue;
            } else {
                $item=mb_substr($item,0,160);
            }
            $out[$item]=$item;
            if (count($out)>=50) break;
        }
        return array_values($out);
    }

    /** @return array<string,mixed>|null */
    public static function classifyUrl(string $url): ?array {
        $host=parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host==='') return null;
        $host=strtolower(rtrim($host,'.'));
        $siteHost=(string)parse_url(home_url('/'), PHP_URL_HOST);
        if ($siteHost!=='' && hash_equals(strtolower($siteHost),$host)) {
            $byMarker = self::classifyContent($url);
            return $byMarker;
        }
        foreach (self::all() as $service) {
            if (empty($service['active']) || !is_array($service['hosts'] ?? null)) continue;
            foreach ($service['hosts'] as $candidate) {
                $candidate=strtolower((string)$candidate);
                if ($candidate!=='' && ($host===$candidate || str_ends_with($host,'.'.$candidate))) return $service;
            }
        }
        return ['id'=>'unknown-third-party','name'=>'Unbekannter Drittanbieter','provider'=>$host,'purpose'=>'Nicht klassifizierte externe Ressource.','category'=>'functional','hosts'=>[$host],'markers'=>[],'cookies'=>[],'active'=>true,'internal'=>false];
    }

    /** @return array<string,mixed>|null */
    public static function classifyContent(string $content): ?array {
        $haystack=strtolower($content);
        foreach (self::all() as $service) {
            if (empty($service['active']) || !is_array($service['markers'] ?? null)) continue;
            foreach ($service['markers'] as $marker) {
                $marker=strtolower((string)$marker);
                if ($marker!=='' && str_contains($haystack,$marker)) return $service;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $service */
    public static function save(array $service): void {
        $services=self::all();
        $services[(string)$service['id']]=$service;
        update_option(Settings::OPTION_SERVICES,$services,false);
        Settings::bumpPolicyVersion();
    }

    public static function delete(string $id): void {
        $id=sanitize_key($id);
        $services=self::all();
        if (isset(self::defaults()[$id])) { throw new ValidationException('Built-in service definitions cannot be deleted; disable them instead.'); }
        unset($services[$id]);
        update_option(Settings::OPTION_SERVICES,$services,false);
        Settings::bumpPolicyVersion();
    }

    /** @return array<int,array<string,mixed>> */
    public static function publicServices(): array {
        $out=[];
        foreach (self::all() as $service) {
            if (empty($service['active'])) continue;
            $out[]=['id'=>$service['id'],'name'=>$service['name'],'provider'=>$service['provider'],'purpose'=>$service['purpose'],'category'=>$service['category'],'hosts'=>$service['hosts'],'markers'=>$service['markers'],'cookies'=>$service['cookies']];
        }
        return $out;
    }
}
