<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use JsonSerializable;
use Koriym\SemanticLogger\AbstractContext;
use Override;

/**
 * Event: tag invalidation outcome across the local pools and the CDN purger.
 *
 * Serialized with self-describing status words rather than raw booleans:
 *   roPool/etagPool -> "invalidated" | "failed"  (Symfony tag invalidation marks
 *                       the tag version stale; it does not physically delete)
 *   cdn             -> "purged" | "failed" | "skipped"
 *                       ("skipped" = the bound purger is NullPurger, i.e. no CDN
 *                       is configured — nothing was purged, but nothing was
 *                       meant to be)
 */
final class InvalidateContext extends AbstractContext implements JsonSerializable
{
    public const TYPE = 'invalidate';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/invalidate.json';

    /**
     * @param list<string>                $tags
     * @param "purged"|"failed"|"skipped" $cdnStatus
     */
    public function __construct(
        public readonly array $tags,
        public readonly bool $roPoolInvalidated,
        public readonly bool $etagPoolInvalidated,
        public readonly string $cdnStatus,
        public readonly float $durationMs,
    ) {
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'tags' => $this->tags,
            'roPool' => $this->roPoolInvalidated ? 'invalidated' : 'failed',
            'etagPool' => $this->etagPoolInvalidated ? 'invalidated' : 'failed',
            'cdn' => $this->cdnStatus,
            'durationMs' => $this->durationMs,
        ];
    }
}
