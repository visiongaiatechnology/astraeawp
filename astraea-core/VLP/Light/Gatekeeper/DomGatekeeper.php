<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\VLP\Light\Gatekeeper;

use Astraea\VLP\Light\Consent\ConsentManager;
use Astraea\VLP\Light\Consent\ServiceRegistry;

final class DomGatekeeper {
    private const RESOURCE_TAGS=['SCRIPT','IFRAME','IMG','LINK','SOURCE','VIDEO','AUDIO','TRACK','EMBED','OBJECT','INPUT'];

    public static function filterHtml(string $html): string {
        if ($html==='' || stripos($html,'<html')===false || !class_exists('WP_HTML_Tag_Processor')) return $html;
        $state=ConsentManager::current();
        $choices=$state['valid'] ? $state['choices'] : ConsentManager::defaults();
        try {
            $p=new \WP_HTML_Tag_Processor($html);
            while ($p->next_tag()) {
                $tag=strtoupper((string)$p->get_tag());
                if (!in_array($tag,self::RESOURCE_TAGS,true)) continue;
                self::processTag($p,$tag,$choices);
            }
            $html=$p->get_updated_html();
        } catch (\Throwable) { return $html; }
        return self::blockKnownInlineScripts($html,$choices);
    }

    /** @param array<string,bool> $choices */
    private static function processTag(\WP_HTML_Tag_Processor $p,string $tag,array $choices): void {
        $candidates=[];
        foreach (['src','href','srcset','poster','data'] as $attr) {
            $value=$p->get_attribute($attr);
            if (is_string($value) && trim($value)!=='') $candidates[$attr]=$value;
        }
        $style=$p->get_attribute('style');
        if (is_string($style) && preg_match_all('~url\([\"\']?(https?://[^)\"\']+)~i',$style,$m)) {
            foreach ($m[1] as $url) $candidates['style']=$url;
        }
        if ($tag==='INPUT' && strtolower((string)$p->get_attribute('type'))!=='image') return;
        $service=null;
        foreach ($candidates as $value) {
            foreach (self::extractUrls($value) as $url) { $service=ServiceRegistry::classifyUrl($url); if ($service!==null) break 2; }
        }
        if ($service===null) return;
        $category=(string)($service['category']??'functional');
        if ($category==='necessary' || !empty($choices[$category])) return;
        $p->set_attribute('data-vlp-blocked','1');
        $p->set_attribute('data-vlp-category',$category);
        $p->set_attribute('data-vlp-service',(string)($service['id']??'unknown-third-party'));
        foreach ($candidates as $attr=>$value) {
            if ($attr==='style') { $p->set_attribute('data-vlp-original-style',$style); $p->set_attribute('style',''); continue; }
            $safeAttr=preg_replace('/[^a-z0-9_-]/i','',$attr) ?: $attr;
            $p->set_attribute('data-vlp-original-'.$safeAttr,$value);
            if ($attr==='src' && $tag==='IFRAME') $p->set_attribute('src','about:blank');
            else $p->remove_attribute($attr);
        }
        if ($tag==='SCRIPT') {
            $type=$p->get_attribute('type');
            if (is_string($type) && $type!=='') $p->set_attribute('data-vlp-original-type',$type);
            $p->set_attribute('type','text/plain');
        }
    }

    /** @return string[] */
    private static function extractUrls(string $value): array {
        $urls=[];
        if (preg_match_all('~https?://[^\s,\"\']+~i',$value,$m)) foreach($m[0] as $u)$urls[]=$u;
        return $urls;
    }

    /** @param array<string,bool> $choices */
    private static function blockKnownInlineScripts(string $html,array $choices): string {
        return (string)preg_replace_callback('~<script\b([^>]*)>(.*?)</script>~is',static function(array $m) use($choices): string {
            $attrs=$m[1]; $body=$m[2];
            if (preg_match('/\bsrc\s*=/i',$attrs) || preg_match('/\bdata-vlp-blocked\s*=/', $attrs)) return $m[0];
            $service=ServiceRegistry::classifyContent($body);
            if ($service===null) return $m[0];
            $category=(string)($service['category']??'functional');
            if ($category==='necessary' || !empty($choices[$category])) return $m[0];
            $origType='';
            if (preg_match('/\btype\s*=\s*([\"\'])(.*?)\1/is',$attrs,$tm)) { $origType=$tm[2]; $attrs=preg_replace('/\s*\btype\s*=\s*([\"\']).*?\1/is','',$attrs) ?? $attrs; }
            $extra=' type="text/plain" data-vlp-blocked="1" data-vlp-category="'.esc_attr($category).'" data-vlp-service="'.esc_attr((string)$service['id']).'"';
            if ($origType!=='') $extra.=' data-vlp-original-type="'.esc_attr($origType).'"';
            return '<script'.$attrs.$extra.'>'.$body.'</script>';
        },$html);
    }
}
