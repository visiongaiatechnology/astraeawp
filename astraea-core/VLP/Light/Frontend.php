<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\VLP\Light;

use Astraea\Security\SecurityEventManager;
use Astraea\VLP\Light\Consent\ConsentManager;
use Astraea\VLP\Light\Consent\ServiceRegistry;
use Astraea\VLP\Light\Gatekeeper\DomGatekeeper;

final class Frontend {
    private static bool $buffering=false;
    public static function init(): void {
        add_action('template_redirect',[self::class,'startBuffer'],PHP_INT_MIN);
        add_action('wp_enqueue_scripts',[self::class,'enqueue'],0);
        add_action('wp_footer',[self::class,'renderBanner'],99999);
        add_action('wp_ajax_astraea_vlp_state',[self::class,'state']);
        add_action('wp_ajax_nopriv_astraea_vlp_state',[self::class,'state']);
        add_action('wp_ajax_astraea_vlp_consent',[self::class,'save']);
        add_action('wp_ajax_nopriv_astraea_vlp_consent',[self::class,'save']);
        add_action('wp_ajax_astraea_vlp_reset',[self::class,'reset']);
        add_action('wp_ajax_nopriv_astraea_vlp_reset',[self::class,'reset']);
        if (Settings::strictCache()) add_action('send_headers',[self::class,'cacheSafety'],0);
    }
    public static function startBuffer(): void {
        if (!Settings::isEnabled() || is_admin() || wp_doing_ajax() || is_feed() || (defined('REST_REQUEST')&&REST_REQUEST)) return;
        $scan=(string)($_SERVER['HTTP_X_ASTRAEA_VLP_SCANNER']??'');
        if ($scan==='1') return;
        ob_start([DomGatekeeper::class,'filterHtml']); self::$buffering=true;
    }
    public static function cacheSafety(): void {
        if (is_admin() || wp_doing_ajax()) return;
        nocache_headers();
        header('Vary: Cookie',false);
        header('X-Astraea-VLP-Cache: private-consent',true);
    }
    public static function enqueue(): void {
        if (!Settings::isEnabled() || is_admin()) return;
        $base=site_url('/astraea-core/VLP/Light/assets/');
        wp_enqueue_style('astraea-vlp-light',$base.'css/vlp-banner.css',[],Settings::VERSION);
        wp_enqueue_script('astraea-vlp-light',$base.'js/vlp-banner.js',[],Settings::VERSION,['strategy'=>'defer','in_footer'=>false]);
        wp_localize_script('astraea-vlp-light','AstraeaVLP',[ 'ajaxUrl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('astraea_vlp_frontend'),'services'=>ServiceRegistry::publicServices(),'dattrack'=>Settings::dattrackEnabled(),'policyVersion'=>Settings::policyVersion(),'categories'=>Settings::CATEGORIES,'initialConsent'=>ConsentManager::current() ]);
    }
    public static function renderBanner(): void {
        if (!Settings::isEnabled()) return;
        $consent = ConsentManager::current();
        $hiddenAttr = !empty($consent['valid']) ? ' hidden' : '';
        $privacyUrl = Settings::privacyUrl();
        ?>
        <div id="astraea-vlp" class="avlp"<?php echo $hiddenAttr; ?> aria-live="polite">
          <div class="avlp-card" role="dialog" aria-modal="true" aria-labelledby="avlp-title">
            <div class="avlp-topbar">
              <div class="avlp-brand"><span class="avlp-orb"></span><span>VLP · VisionLegalPro <b>Light</b></span></div>
              <?php if ($privacyUrl !== ''): ?>
                <a href="<?php echo esc_url($privacyUrl); ?>" class="avlp-privacy-top-link" target="_blank" rel="noopener noreferrer"><?php echo esc_html(function_exists('astraea_t') ? astraea_t('vlp_privacy_policy') : 'Datenschutz'); ?> &rarr;</a>
              <?php endif; ?>
            </div>
            <h2 id="avlp-title"><?php echo esc_html(Settings::title()); ?></h2>
            <p><?php echo esc_html(Settings::text()); ?></p>
            <div id="avlp-detail" class="avlp-detail" hidden>
              <?php foreach (Settings::CATEGORIES as $category): $required=$category==='necessary'; ?>
                <label class="avlp-toggle-row"><span><strong><?php echo esc_html(self::categoryLabel($category)); ?></strong><small><?php echo esc_html(self::categoryDescription($category)); ?></small></span><input type="checkbox" data-vlp-choice="<?php echo esc_attr($category); ?>" <?php checked($required); disabled($required); ?>><i></i></label>
              <?php endforeach; ?>
              <div class="avlp-services" id="avlp-services"></div>
            </div>
            <div class="avlp-actions">
              <button type="button" class="avlp-btn avlp-btn-ghost" data-vlp-action="settings" onclick="if(window.AstraeaVLP&&window.AstraeaVLP.toggleSettings){window.AstraeaVLP.toggleSettings();}return false;"><?php echo esc_html(function_exists('astraea_t') ? astraea_t('vlp_customize') : 'Einstellungen'); ?></button>
              <button type="button" class="avlp-btn avlp-btn-ghost" data-vlp-action="reject" onclick="if(window.AstraeaVLP&&window.AstraeaVLP.rejectAll){window.AstraeaVLP.rejectAll();}return false;"><?php echo esc_html(function_exists('astraea_t') ? astraea_t('vlp_accept_essential') : 'Nur notwendige'); ?></button>
              <button type="button" class="avlp-btn avlp-btn-primary" data-vlp-action="accept" onclick="if(window.AstraeaVLP&&window.AstraeaVLP.acceptAll){window.AstraeaVLP.acceptAll();}return false;"><?php echo esc_html(function_exists('astraea_t') ? astraea_t('vlp_accept_all') : 'Alle akzeptieren'); ?></button>
              <button type="button" class="avlp-btn avlp-btn-primary" data-vlp-action="save" onclick="if(window.AstraeaVLP&&window.AstraeaVLP.saveChoices){window.AstraeaVLP.saveChoices();}return false;" hidden><?php echo esc_html(function_exists('astraea_t') ? astraea_t('vlp_save_preferences') : 'Auswahl speichern'); ?></button>
            </div>
            <?php if ($privacyUrl !== ''): ?>
              <div class="avlp-footer-links">
                <a href="<?php echo esc_url($privacyUrl); ?>" class="avlp-privacy-link" target="_blank" rel="noopener noreferrer"><?php echo esc_html(function_exists('astraea_t') ? astraea_t('vlp_privacy_policy') : 'Datenschutzerklärung'); ?></a>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <script>
        (function(){
          try{
            var b=document.getElementById('astraea-vlp');
            if(!b)return;
            var q=function(s){return b.querySelector(s);};
            var qa=function(s){return Array.prototype.slice.call(b.querySelectorAll(s));};
            var hide=function(){b.hidden=true;b.setAttribute('hidden','hidden');b.style.display='none';};
            var toggle=function(){
              var d=q('#avlp-detail');
              if(!d)return;
              var isH=d.hidden||d.hasAttribute('hidden')||d.style.display==='none';
              if(isH){d.hidden=false;d.removeAttribute('hidden');d.style.display='grid';}
              else{d.hidden=true;d.setAttribute('hidden','hidden');d.style.display='none';}
              var sv=q('[data-vlp-action="save"]');
              if(sv){
                sv.hidden=!isH;
                if(isH){sv.removeAttribute('hidden');sv.style.display='';}
                else{sv.setAttribute('hidden','hidden');sv.style.display='none';}
              }
            };
            var saveLocal=function(choices){
              try{
                var p=window.AstraeaVLP&&window.AstraeaVLP.policyVersion?window.AstraeaVLP.policyVersion:1;
                localStorage.setItem('astraea_vlp_consent',JSON.stringify({valid:true,policy_version:p,choices:choices}));
              }catch(e){}
              hide();
            };
            var sendAjax=function(choices){
              try{
                var ajaxUrl=(window.AstraeaVLP&&window.AstraeaVLP.ajaxUrl)?window.AstraeaVLP.ajaxUrl:'<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                var nonce=(window.AstraeaVLP&&window.AstraeaVLP.nonce)?window.AstraeaVLP.nonce:'<?php echo esc_js(wp_create_nonce('astraea_vlp_frontend')); ?>';
                var fd=new FormData();
                fd.append('action','astraea_vlp_consent');
                fd.append('nonce',nonce);
                for(var k in choices){if(Object.prototype.hasOwnProperty.call(choices,k))fd.append(k,choices[k]?'1':'0');}
                if(window.fetch){fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).catch(function(){});}
              }catch(e){}
            };
            var onAction=function(act){
              if(act==='settings'){
                if(window.AstraeaVLP&&window.AstraeaVLP.toggleSettings){window.AstraeaVLP.toggleSettings();}else{toggle();}
              }else if(act==='accept'){
                if(window.AstraeaVLP&&window.AstraeaVLP.acceptAll){window.AstraeaVLP.acceptAll();}
                else{var c={necessary:true,functional:true,statistics:true,marketing:true,external_media:true};saveLocal(c);sendAjax(c);}
              }else if(act==='reject'){
                if(window.AstraeaVLP&&window.AstraeaVLP.rejectAll){window.AstraeaVLP.rejectAll();}
                else{var c={necessary:true,functional:false,statistics:false,marketing:false,external_media:false};saveLocal(c);sendAjax(c);}
              }else if(act==='save'){
                if(window.AstraeaVLP&&window.AstraeaVLP.saveChoices){window.AstraeaVLP.saveChoices();}
                else{
                  var c={necessary:true};
                  qa('[data-vlp-choice]').forEach(function(i){c[i.getAttribute('data-vlp-choice')]=Boolean(i.checked);});
                  saveLocal(c);sendAjax(c);
                }
              }
            };
            qa('[data-vlp-action]').forEach(function(btn){
              btn.addEventListener('click',function(e){
                e.preventDefault();
                e.stopPropagation();
                onAction(btn.getAttribute('data-vlp-action'));
              });
            });
            window.AstraeaVLP=window.AstraeaVLP||{};
            if(!window.AstraeaVLP.toggleSettings)window.AstraeaVLP.toggleSettings=toggle;
            if(!window.AstraeaVLP.hide)window.AstraeaVLP.hide=hide;
          }catch(e){}
        })();
        </script><?php
    }
    public static function state(): never {
        try {
            self::assertSameOrigin(false);
            wp_send_json_success(ConsentManager::current());
        } catch (\Throwable) {
            wp_send_json_success(ConsentManager::current());
        }
    }
    public static function save(): never {
        try {
            self::assertSameOrigin(true);
            $choices = [];
            foreach (Settings::CATEGORIES as $cat) {
                $choices[$cat] = isset($_POST[$cat]) && wp_unslash((string)$_POST[$cat]) === '1';
            }
            $state = ConsentManager::issue($choices);
            SecurityEventManager::recordEvent(
                SecurityEventManager::SEVERITY_INFO,
                'VLP',
                'consent_updated',
                'Visitor consent preferences were updated.',
                ['policy_version' => $state['policy_version']]
            );
            wp_send_json_success($state);
        } catch (\Throwable $e) {
            wp_send_json_error(['status' => 'rejected', 'error' => $e->getMessage()], 403);
        }
    }
    public static function reset(): never {
        try {
            self::assertSameOrigin(true);
            ConsentManager::clear();
            wp_send_json_success(['status' => 'reset']);
        } catch (\Throwable $e) {
            wp_send_json_error(['status' => 'rejected', 'error' => $e->getMessage()], 403);
        }
    }
    private static function assertSameOrigin(bool $nonce): void {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
        if ($method !== ($nonce ? 'POST' : 'GET')) throw new \RuntimeException('method');
        if ($nonce) {
            $valid = check_ajax_referer('astraea_vlp_frontend', 'nonce', false);
            if (!$valid) throw new \RuntimeException('nonce');
        }
        $allowedHosts = [];
        foreach ([home_url('/'), site_url('/')] as $u) {
            $h = parse_url($u, PHP_URL_HOST);
            if (is_string($h) && $h !== '') {
                $h = strtolower(explode(':', $h)[0]);
                $allowedHosts[$h] = true;
                $allowedHosts[preg_replace('/^www\./', '', $h)] = true;
            }
        }
        if (!empty($_SERVER['HTTP_HOST'])) {
            $h = strtolower(explode(':', (string)$_SERVER['HTTP_HOST'])[0]);
            $allowedHosts[$h] = true;
            $allowedHosts[preg_replace('/^www\./', '', $h)] = true;
        }
        if (!empty($_SERVER['SERVER_NAME'])) {
            $h = strtolower(explode(':', (string)$_SERVER['SERVER_NAME'])[0]);
            $allowedHosts[$h] = true;
            $allowedHosts[preg_replace('/^www\./', '', $h)] = true;
        }
        $source = '';
        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $hdr) {
            if (!empty($_SERVER[$hdr]) && is_string($_SERVER[$hdr])) {
                $source = $_SERVER[$hdr];
                break;
            }
        }
        if ($source !== '') {
            $host = strtolower((string)parse_url($source, PHP_URL_HOST));
            $host = explode(':', $host)[0];
            $bare = preg_replace('/^www\./', '', $host);
            if ($host === '' || (!isset($allowedHosts[$host]) && !isset($allowedHosts[$bare]))) {
                throw new \RuntimeException('origin');
            }
        }
    }
    private static function categoryLabel(string $c): string {
        if (function_exists('astraea_t')) {
            $key = match($c) {
                'necessary'      => 'vlp_category_essential',
                'functional'     => 'vlp_category_functional',
                'statistics'     => 'vlp_category_analytics',
                'marketing'      => 'vlp_category_marketing',
                'external_media' => 'vlp_category_external_media',
                default          => null,
            };
            if ($key !== null && function_exists('astraea_has_t') && astraea_has_t($key)) {
                return astraea_t($key);
            }
            if ($key !== null) {
                $translated = astraea_t($key);
                if ($translated !== $key && $translated !== '') {
                    return $translated;
                }
            }
        }
        return match($c){'necessary'=>'Notwendig','functional'=>'Funktional','statistics'=>'Statistik','marketing'=>'Marketing','external_media'=>'Externe Medien',default=>$c};
    }
    private static function categoryDescription(string $c): string {
        if (function_exists('astraea_t')) {
            $key = match($c) {
                'necessary'      => 'vlp_category_essential_desc',
                'functional'     => 'vlp_category_functional_desc',
                'statistics'     => 'vlp_category_analytics_desc',
                'marketing'      => 'vlp_category_marketing_desc',
                'external_media' => 'vlp_category_external_media_desc',
                default          => null,
            };
            if ($key !== null && function_exists('astraea_has_t') && astraea_has_t($key)) {
                return astraea_t($key);
            }
            if ($key !== null) {
                $translated = astraea_t($key);
                if ($translated !== $key && $translated !== '') {
                    return $translated;
                }
            }
        }
        return match($c){'necessary'=>'Für Betrieb, Sicherheit und deine Auswahl erforderlich.','functional'=>'Optionale Funktionen und externe Hilfsdienste.','statistics'=>'Reichweiten- und Nutzungsanalyse, inklusive lokalem Dattrack.','marketing'=>'Marketing-, Conversion- und Werbedienste.','external_media'=>'Videos, Karten und andere Inhalte externer Anbieter.',default=>''};
    }
}
