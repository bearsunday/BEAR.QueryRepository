<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Override;

use function str_contains;
use function strtolower;

/**
 * {@inheritDoc}
 *
 * Three conditions make a response unstorable (issue #222): a producer called
 * UncacheableMarkInterface::mark() during this request, Cache-Control already contains
 * no-store, or it contains private - private because this package is a shared cache in the
 * sense of RFC 9111: whatever it stores is served to every later visitor. `isPrivate`/`noStore`
 * applied dynamically by `#[HttpCache(isPrivate: true)]` is unaffected: $declaredCacheControl
 * lets the caller exempt its own attribute's value from the check.
 */
final readonly class UnstorableGate implements UnstorableGateInterface
{
    private const REASON = 'unstorable';

    public function __construct(
        private UncacheableMarkInterface $mark,
    ) {
    }

    /** @return 'unstorable'|null */
    #[Override]
    public function reason(ResourceObject $ro, string|null $declaredCacheControl = null): string|null
    {
        if ($this->mark->isMarked()) {
            return self::REASON;
        }

        $cacheControl = $ro->headers[Header::CACHE_CONTROL] ?? '';
        if ($cacheControl === '' || $cacheControl === $declaredCacheControl) {
            return null;
        }

        $lower = strtolower($cacheControl);
        if (str_contains($lower, 'no-store') || str_contains($lower, 'private')) {
            return self::REASON;
        }

        return null;
    }
}
