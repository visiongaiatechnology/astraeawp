<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\VLP\Light\Consent;

use Astraea\Crypto\KeyContext;
use Astraea\Crypto\Keyring;
use Astraea\Exceptions\SecurityException;
use Astraea\VLP\Light\Settings;

final class ConsentManager {
    /** @return array{necessary:bool,functional:bool,statistics:bool,marketing:bool,external_media:bool} */
    public static function defaults(): array { return ['necessary'=>true,'functional'=>false,'statistics'=>false,'marketing'=>false,'external_media'=>false]; }

    /** @return array{valid:bool,policy_version:int,choices:array<string,bool>,updated_at:int} */
    public static function current(): array {
        $raw=$_COOKIE[Settings::COOKIE_NAME] ?? '';
        if (!is_string($raw) || $raw==='') return ['valid'=>false,'policy_version'=>Settings::policyVersion(),'choices'=>self::defaults(),'updated_at'=>0];
        try { return self::decode($raw); } catch (\Throwable) { return ['valid'=>false,'policy_version'=>Settings::policyVersion(),'choices'=>self::defaults(),'updated_at'=>0]; }
    }

    /** @param array<string,mixed> $choices */
    public static function issue(array $choices): array {
        $normalized=self::normalizeChoices($choices);
        $record=Keyring::getActiveKey();
        $payload=['v'=>1,'policy'=>Settings::policyVersion(),'iat'=>time(),'choices'=>$normalized];
        $json=json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $body=self::b64($json);
        $key=$record->deriveSubkey(KeyContext::VLP_CONSENT,32);
        $mac=hash_hmac('sha256','vlp-consent-v1|'.$record->keyId.'|'.$body,$key,true);
        $receipt='v1.'.$record->keyId.'.'.$body.'.'.self::b64($mac);
        setcookie(Settings::COOKIE_NAME,$receipt,[
            'expires'=>time()+Settings::CONSENT_TTL,'path'=>'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax'
        ]);
        $_COOKIE[Settings::COOKIE_NAME]=$receipt;
        return ['valid'=>true,'policy_version'=>$payload['policy'],'choices'=>$normalized,'updated_at'=>$payload['iat']];
    }

    /** @return array{valid:bool,policy_version:int,choices:array<string,bool>,updated_at:int} */
    public static function decode(string $receipt): array {
        $parts=explode('.',$receipt);
        if (count($parts)!==4 || $parts[0]!=='v1' || preg_match('/^[a-f0-9]{8}$/D',$parts[1])!==1) throw new SecurityException('Consent token structure validation failed.');
        [, $keyId,$body,$macB64]=$parts;
        $record=Keyring::resolveKey($keyId);
        $key=$record->deriveSubkey(KeyContext::VLP_CONSENT,32);
        $expected=hash_hmac('sha256','vlp-consent-v1|'.$keyId.'|'.$body,$key,true);
        $mac=self::b64d($macB64);
        if (!is_string($mac) || !hash_equals($expected,$mac)) throw new SecurityException('Consent token authentication failed.');
        $json=self::b64d($body);
        if (!is_string($json)) throw new SecurityException('Consent token payload validation failed.');
        $payload=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($payload) || (int)($payload['v']??0)!==1) throw new SecurityException('Consent token version validation failed.');
        $policy=(int)($payload['policy']??0);
        $iat=(int)($payload['iat']??0);
        if ($policy!==Settings::policyVersion() || $iat<=0 || $iat>time()+60 || time()-$iat>Settings::CONSENT_TTL) throw new SecurityException('Consent token policy or lifetime validation failed.');
        return ['valid'=>true,'policy_version'=>$policy,'choices'=>self::normalizeChoices(is_array($payload['choices']??null)?$payload['choices']:[]),'updated_at'=>$iat];
    }

    /** @param array<string,mixed> $choices @return array<string,bool> */
    public static function normalizeChoices(array $choices): array {
        $out=self::defaults();
        foreach (Settings::CATEGORIES as $category) {
            if ($category==='necessary') { $out[$category]=true; continue; }
            $out[$category]=filter_var($choices[$category]??false,FILTER_VALIDATE_BOOL);
        }
        return $out;
    }

    public static function categoryAllowed(string $category): bool {
        if ($category==='necessary') return true;
        $state=self::current();
        return $state['valid'] && !empty($state['choices'][$category]);
    }

    public static function clear(): void {
        setcookie(Settings::COOKIE_NAME,'',['expires'=>time()-3600,'path'=>'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);
        unset($_COOKIE[Settings::COOKIE_NAME]);
    }

    private static function b64(string $data): string { return rtrim(strtr(base64_encode($data),'+/','-_'),'='); }
    private static function b64d(string $data): string|false { $r=strlen($data)%4; if($r)$data.=str_repeat('=',4-$r); return base64_decode(strtr($data,'-_','+/'),true); }
}
