<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Redirects;

use Astraea\Exceptions\ValidationException;

/**
 * Validated model for a routing redirect rule.
 *
 * @package Astraea\Redirects
 */
final class RedirectRule {

    public const MATCH_EXACT    = 'exact';
    public const MATCH_WILDCARD = 'wildcard';
    public const MATCH_REGEX    = 'regex';

    public function __construct(
        public readonly string $id,
        public readonly string $source,
        public readonly string $target,
        public readonly int $code = 301,
        public readonly string $matchType = self::MATCH_EXACT,
        public readonly int $hits = 0,
        public readonly int $created = 0,
        public readonly bool $active = true
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        $source = trim((string)($data['source'] ?? ''));
        $target = trim((string)($data['target'] ?? ''));
        if ($source === '' || $target === '') {
            throw new ValidationException('Redirect source and target URLs are required.');
        }

        if (str_contains($source, "\r") || str_contains($source, "\n") || str_contains($source, "\0")
            || str_contains($target, "\r") || str_contains($target, "\n") || str_contains($target, "\0")) {
            throw new ValidationException('CRLF, newline, and null-byte characters are forbidden in redirect rules.');
        }

        if (str_starts_with($target, '//')) {
            throw new ValidationException('Protocol-relative redirect targets are forbidden.');
        }

        if (!str_starts_with($target, '/')) {
            $scheme = strtolower((string)parse_url($target, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new ValidationException('Redirect target URL scheme must be HTTP or HTTPS.');
            }
        }

        $code = (int)($data['code'] ?? 301);
        if (!in_array($code, [301, 302, 307, 308], true)) {
            $code = 301;
        }

        $matchType = (string)($data['match_type'] ?? self::MATCH_EXACT);
        if (!in_array($matchType, [self::MATCH_EXACT, self::MATCH_WILDCARD, self::MATCH_REGEX], true)) {
            $matchType = self::MATCH_EXACT;
        }

        $id = (string)($data['id'] ?? bin2hex(random_bytes(8)));
        $hits = (int)($data['hits'] ?? 0);
        $created = (int)($data['created'] ?? time());
        $active = (bool)($data['active'] ?? true);

        return new self($id, $source, $target, $code, $matchType, $hits, $created, $active);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'id'         => $this->id,
            'source'     => $this->source,
            'target'     => $this->target,
            'code'       => $this->code,
            'match_type' => $this->matchType,
            'hits'       => $this->hits,
            'created'    => $this->created,
            'active'     => $this->active,
        ];
    }
}
