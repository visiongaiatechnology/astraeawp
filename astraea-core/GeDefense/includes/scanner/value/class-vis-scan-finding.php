<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

if (!defined('ABSPATH')) exit('VGT_ACCESS_DENIED');

final readonly class VIS_Scan_Finding {
    public const CLASS_MALWARE = 'MALWARE';
    public const CLASS_SUSPICIOUS = 'SUSPICIOUS';
    public const CLASS_POLICY = 'POLICY';
    public const CLASS_INFO = 'INFO';

    public function __construct(
        public string $code,
        public int $risk,
        public int $confidence,
        public string $message,
        public bool $quarantineEligible = false,
        public string $classification = self::CLASS_SUSPICIOUS
    ) {
        if (preg_match('/^[A-Z0-9_]{3,64}$/D', $code) !== 1
            || $risk < 0 || $risk > 100
            || $confidence < 0 || $confidence > 100
            || !in_array($classification, [self::CLASS_MALWARE, self::CLASS_SUSPICIOUS, self::CLASS_POLICY, self::CLASS_INFO], true)) {
            throw new ValidationException('Invalid malware finding.');
        }
        if ($quarantineEligible && $classification !== self::CLASS_MALWARE) {
            throw new ValidationException('Only malware evidence may be quarantine eligible.');
        }
    }

    public function isBlockingEvidence(): bool {
        return $this->classification === self::CLASS_MALWARE
            && $this->risk >= 80
            && $this->confidence >= 75;
    }

    /** @return array{code:string,risk:int,confidence:int,message:string,quarantine_eligible:bool,classification:string} */
    public function toArray(): array {
        return [
            'code' => $this->code,
            'risk' => $this->risk,
            'confidence' => $this->confidence,
            'message' => $this->message,
            'quarantine_eligible' => $this->quarantineEligible,
            'classification' => $this->classification,
        ];
    }
}
