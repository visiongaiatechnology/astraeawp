<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

if (!defined('ABSPATH')) exit('VGT_ACCESS_DENIED');

final readonly class VIS_Scan_Verdict {
    /** @param list<VIS_Scan_Finding> $findings */
    public function __construct(
        public string $sha256,
        public int $risk,
        public int $confidence,
        public array $findings,
        public bool $truncated
    ) {}

    public function shouldBlock(): bool {
        foreach ($this->findings as $finding) {
            if ($finding instanceof VIS_Scan_Finding && $finding->isBlockingEvidence()) {
                return true;
            }
        }
        return false;
    }

    public function shouldQuarantine(): bool {
        foreach ($this->findings as $finding) {
            if (!$finding instanceof VIS_Scan_Finding) continue;
            if ($finding->classification !== VIS_Scan_Finding::CLASS_MALWARE) continue;
            if (!$finding->quarantineEligible) continue;
            if ($finding->risk >= 95 && $finding->confidence >= 90) return true;
        }
        return false;
    }

    /** @return array{sha256:string,risk:int,confidence:int,truncated:bool,findings:list<array<string,mixed>>,blocking:bool} */
    public function toArray(): array {
        return [
            'sha256' => $this->sha256,
            'risk' => $this->risk,
            'confidence' => $this->confidence,
            'truncated' => $this->truncated,
            'blocking' => $this->shouldBlock(),
            'findings' => array_map(static fn(VIS_Scan_Finding $finding): array => $finding->toArray(), $this->findings),
        ];
    }
}
