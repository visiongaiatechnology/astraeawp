<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\VLP\Light\Scanner;

use Astraea\Exceptions\SecurityException;
use Astraea\VLP\Light\Consent\ServiceRegistry;

final class ScannerService {
    private const MAX_FILES=2500;
    private const MAX_FILE_BYTES=1048576;
    private const MAX_HTML_BYTES=2097152;
    private const EXTENSIONS=['php','js','mjs','css','html','htm','twig','json'];

    /** @return array<string,mixed> */
    public static function scan(): array {
        $findings=[];
        self::scanRenderedHome($findings);
        $roots=[];
        if (defined('WP_PLUGIN_DIR')) $roots[]=['type'=>'plugins','path'=>WP_PLUGIN_DIR];
        if (function_exists('get_stylesheet_directory')) $roots[]=['type'=>'theme','path'=>get_stylesheet_directory()];
        $scanned=0;
        foreach ($roots as $root) self::scanRoot((string)$root['path'],(string)$root['type'],$findings,$scanned);
        $findings=array_values($findings);
        usort($findings,static fn(array $a,array $b): int => strcmp((string)$a['source'],(string)$b['source']));
        return ['scanned_files'=>$scanned,'findings'=>array_slice($findings,0,500),'scanned_at'=>gmdate('c')];
    }

    /** @param array<string,array<string,mixed>> $findings */
    private static function scanRenderedHome(array &$findings): void {
        $url=home_url('/');
        $response=wp_remote_get($url,['timeout'=>6,'redirection'=>0,'limit_response_size'=>self::MAX_HTML_BYTES,'headers'=>['X-Astraea-VLP-Scanner'=>'1']]);
        if (is_wp_error($response)) return;
        $body=wp_remote_retrieve_body($response);
        self::inspectContent($body,'rendered-home',$findings);
        $headers=wp_remote_retrieve_headers($response);
        foreach ($headers as $name=>$value) {
            if (strtolower((string)$name)==='set-cookie') {
                $values=is_array($value)?$value:[$value];
                foreach ($values as $cookie) self::addFinding($findings,'cookie','rendered-home',mb_substr((string)$cookie,0,300),null);
            }
        }
    }

    /** @param array<string,array<string,mixed>> $findings */
    private static function scanRoot(string $input,string $source,array &$findings,int &$scanned): void {
        $resolved=realpath($input);
        if ($resolved===false || !is_dir($resolved)) return;
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved,\FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($scanned>=self::MAX_FILES) break;
            if (!$item->isFile() || $item->isLink()) continue;
            $path=$item->getPathname();
            $real=realpath($path);
            if ($real===false || (!hash_equals($real,$resolved) && !str_starts_with($real,$resolved.DIRECTORY_SEPARATOR))) throw new SecurityException('Scanner path escaped jail.');
            $ext=strtolower(pathinfo($real,PATHINFO_EXTENSION)); if(!in_array($ext,self::EXTENSIONS,true)) continue;
            $size=filesize($real); if(!is_int($size)||$size<=0||$size>self::MAX_FILE_BYTES) continue;
            $data=file_get_contents($real,false,null,0,self::MAX_FILE_BYTES); if(!is_string($data)) continue;
            $scanned++;
            self::inspectContent($data,$source.':'.ltrim(substr($real,strlen($resolved)),DIRECTORY_SEPARATOR),$findings);
        }
    }

    /** @param array<string,array<string,mixed>> $findings */
    private static function inspectContent(string $data,string $source,array &$findings): void {
        if (preg_match_all('~https?://([a-z0-9.-]+)(?:[:/][^\s\"\'<>)]*)?~i',$data,$m)) {
            foreach ($m[0] as $i=>$url) {
                $service=ServiceRegistry::classifyUrl((string)$url);
                if ($service!==null) self::addFinding($findings,'external-resource',$source,(string)$url,$service);
            }
        }
        foreach (ServiceRegistry::all() as $service) {
            if (empty($service['active']) || !is_array($service['markers']??null)) continue;
            foreach ($service['markers'] as $marker) {
                if ((string)$marker!=='' && stripos($data,(string)$marker)!==false) self::addFinding($findings,'service-marker',$source,(string)$marker,$service);
            }
        }
        if (preg_match('/\b(?:setcookie\s*\(|document\.cookie\s*=)/i',$data)) self::addFinding($findings,'cookie-write',$source,'Cookie write detected in source.',null);
    }

    /** @param array<string,array<string,mixed>> $findings @param array<string,mixed>|null $service */
    private static function addFinding(array &$findings,string $type,string $source,string $evidence,?array $service): void {
        $key=hash('sha256',$type.'|'.$source.'|'.$evidence);
        $findings[$key]=['type'=>$type,'source'=>mb_substr($source,0,300),'evidence'=>mb_substr($evidence,0,400),'service_id'=>(string)($service['id']??''),'service_name'=>(string)($service['name']??''),'category'=>(string)($service['category']??'')];
    }
}
