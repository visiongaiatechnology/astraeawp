<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\VLP\Light\Dattrack;

use Astraea\Crypto\CryptoService;
use Astraea\Crypto\KeyContext;
use Astraea\Crypto\Keyring;
use Astraea\Security\SecurityEventManager;
use Astraea\Security\AtomicCounter;
use Astraea\VLP\Light\Consent\ConsentManager;
use Astraea\VLP\Light\Settings;

final class DattrackService {
    private const MAX_BODY=8192;
    private const RATE_LIMIT=60;
    private const MAX_DAILY_GLOBAL_EVENTS = 10000;
    private const RETENTION_DAYS=7;
    public const CRON_HOOK='astraea_vlp_dattrack_purge';

    public static function init(): void {
        add_action('wp_ajax_astraea_vlp_dattrack',[self::class,'ingest']);
        add_action('wp_ajax_nopriv_astraea_vlp_dattrack',[self::class,'ingest']);
        add_action(self::CRON_HOOK,[self::class,'purge']);
        if (!wp_next_scheduled(self::CRON_HOOK)) wp_schedule_event(time()+HOUR_IN_SECONDS,'daily',self::CRON_HOOK);
    }

    public static function ingest(): never {
        try {
            self::assertRequest();
            if (!Settings::dattrackEnabled() || !ConsentManager::categoryAllowed('statistics')) wp_send_json_error(['status'=>'consent_required'],403);
            self::rateLimit();
            $raw=file_get_contents('php://input',false,null,0,self::MAX_BODY+1);
            if (!is_string($raw) || $raw==='' || strlen($raw)>self::MAX_BODY) wp_send_json_error(['status'=>'invalid_payload'],400);
            $data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
            if (!is_array($data)) wp_send_json_error(['status'=>'invalid_payload'],400);
            $path=self::normalizePath((string)($data['path']??'/'));
            $title=mb_substr(sanitize_text_field((string)($data['title']??'')),0,180);
            $refHost=self::referrerHost((string)($data['referrer']??''));
            $language=mb_substr(sanitize_text_field((string)($data['language']??'')),0,24);
            $vw=max(0,min(10000,(int)($data['viewport_width']??0)));
            $vh=max(0,min(10000,(int)($data['viewport_height']??0)));
            if (!AtomicCounter::consume('vlp:dattrack:global', self::MAX_DAILY_GLOBAL_EVENTS, DAY_IN_SECONDS)) {
                throw new \RuntimeException('budget');
            }
            $date=gmdate('Y-m-d');
            $visitor=self::visitorDay($date);
            $payload=['path'=>$path,'title'=>$title,'referrer_host'=>$refHost,'language'=>$language,'viewport'=>[$vw,$vh],'ts'=>time()];
            $json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $aad='vlp:dattrack:'.$date.':'.$visitor;
            $envelope=CryptoService::encrypt($json,KeyContext::VLP_DATTRACK,$aad);
            global $wpdb;
            $table=$wpdb->prefix.'astraea_vlp_dattrack_events';
            $ok=$wpdb->insert($table,['event_date'=>$date,'visitor_day'=>$visitor,'payload'=>$envelope,'created_at'=>gmdate('Y-m-d H:i:s')],['%s','%s','%s','%s']);
            if ($ok===false) throw new \RuntimeException('Dattrack persistence failed.');
            wp_send_json_success(['status'=>'stored']);
        } catch (\JsonException) { wp_send_json_error(['status'=>'invalid_payload'],400); }
        catch (\Throwable $e) {
            SecurityEventManager::recordOnce(SecurityEventManager::SEVERITY_WARNING,'VLP:Dattrack','ingest_failed','Dattrack ingestion failed without exposing request data.',['error_type'=>get_class($e)],30);
            wp_send_json_error(['status'=>'rejected'],400);
        }
    }

    public static function purge(): void {
        global $wpdb; $table=$wpdb->prefix.'astraea_vlp_dattrack_events';
        $cutoff=gmdate('Y-m-d H:i:s',time()-(self::RETENTION_DAYS*DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s",$cutoff));
    }

    /** @return array{events_7d:int,visitor_days_7d:int,last_event:string} */
    public static function metrics(): array {
        global $wpdb; $table=$wpdb->prefix.'astraea_vlp_dattrack_events'; $cutoff=gmdate('Y-m-d H:i:s',time()-7*DAY_IN_SECONDS);
        $row=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS events, COUNT(DISTINCT CONCAT(event_date, ':', visitor_day)) AS visitor_days, MAX(created_at) AS last_event FROM {$table} WHERE created_at >= %s",$cutoff),ARRAY_A);
        return ['events_7d'=>(int)($row['events']??0),'visitor_days_7d'=>(int)($row['visitor_days']??0),'last_event'=>(string)($row['last_event']??'')];
    }

    private static function assertRequest(): void {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST') throw new \RuntimeException('method');
        $nonce = isset($_SERVER['HTTP_X_WP_NONCE']) && is_string($_SERVER['HTTP_X_WP_NONCE']) ? $_SERVER['HTTP_X_WP_NONCE'] : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'astraea_vlp_frontend')) throw new \RuntimeException('nonce');
        $siteHost=strtolower((string)parse_url(home_url('/'),PHP_URL_HOST));
        $source='';
        foreach (['HTTP_ORIGIN','HTTP_REFERER'] as $header) { if (!empty($_SERVER[$header])&&is_string($_SERVER[$header])) { $source=$_SERVER[$header]; break; } }
        $sourceHost=strtolower((string)parse_url($source,PHP_URL_HOST));
        if ($siteHost==='' || $sourceHost==='' || !hash_equals($siteHost,$sourceHost)) throw new \RuntimeException('origin');
    }

    private static function rateLimit(): void {
        $ip=filter_var((string)($_SERVER['REMOTE_ADDR']??''),FILTER_VALIDATE_IP) ?: 'invalid';
        $key='vlp_dt_rl_'.substr(hash_hmac('sha256',$ip,wp_salt('nonce')),0,32);
        if (!AtomicCounter::consume($key, self::RATE_LIMIT, 60)) throw new \RuntimeException('rate');
    }

    private static function visitorDay(string $date): string {
        $ip=filter_var((string)($_SERVER['REMOTE_ADDR']??''),FILTER_VALIDATE_IP) ?: 'invalid';
        $ua=mb_substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,180);
        $key=Keyring::getActiveKey()->deriveSubkey(KeyContext::VLP_DATTRACK,32);
        return hash_hmac('sha256',$date.'|'.$ip.'|'.$ua,$key);
    }
    private static function normalizePath(string $path): string { $parsed=parse_url($path,PHP_URL_PATH); $v=is_string($parsed)?$parsed:'/'; if(!str_starts_with($v,'/'))$v='/'.$v; return mb_substr($v,0,512); }
    private static function referrerHost(string $ref): string { $host=parse_url($ref,PHP_URL_HOST); return is_string($host)?mb_substr(strtolower($host),0,253):''; }
}
