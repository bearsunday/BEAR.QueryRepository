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
 * Three conditions make a response unstorable: a producer called `mark()` during this
 * request, or `Cache-Control` already contains `no-store` or `private` (matched
 * case-insensitively, RFC 7230 §3.2). A value declared by `#[HttpCache]`/`#[NoHttpCache]`
 * is exempt from that check.
 */
final readonly class UnstorableGate implements UnstorableGateInterface
{
    private const REASON = 'unstorable';

    public function __construct(
        private UncacheableMarkScopeInterface $mark,
        private DeclaredCacheControl $declaredCacheControl,
    ) {
    }

    /** @return 'unstorable'|null */
    #[Override]
    public function reason(ResourceObject $ro): string|null
    {
        return $this->reasonFor($ro, $this->isMarkReason($ro));
    }

    /** @return 'unstorable'|null */
    #[Override]
    public function reasonExcludingHoleMarks(ResourceObject $ro): string|null
    {
        return $this->reasonFor($ro, $this->mark->isMarkedForCurrentScope());
    }

    /**
     * {@inheritDoc}
     *
     * Only trusts the own-write-scoped predicate when the innermost open write belongs to
     * $ro by object identity; otherwise falls back to the conservative, any-owner
     * isMarked(), which can only over-refuse, never leak past an untracked mark.
     */
    #[Override]
    public function reasonForOwnWrite(ResourceObject $ro): string|null
    {
        return $this->reasonFor($ro, $this->isMarkReasonForOwnWrite($ro));
    }

    /**
     * {@inheritDoc}
     *
     * The same any-owner predicate reason() trusts, exposed separately so a caller already
     * holding reason()'s result (DonutHeaderSetter) can tell a mark apart from a
     * Cache-Control-only refusal without HeaderSetter re-deriving it - HeaderSetter holds
     * no UncacheableMarkScopeInterface of its own.
     */
    #[Override]
    public function isMarkReason(ResourceObject $ro): bool
    {
        return $this->mark->isMarked();
    }

    /**
     * {@inheritDoc}
     *
     * The same predicate reasonForOwnWrite() trusts, exposed separately so a caller already
     * holding that result (HeaderSetter, handed it by QueryRepository::doPut()) can tell a
     * mark apart from a Cache-Control-only refusal too. Without this split, HeaderSetter's
     * own any-owner isMarked() would wrongly override a #[Cacheable] resource's own
     * Cache-Control header with `private, no-store` whenever an unrelated mark fires
     * elsewhere in the request.
     */
    #[Override]
    public function isMarkReasonForOwnWrite(ResourceObject $ro): bool
    {
        if (! $this->mark->isCurrentWriteFor($ro)) {
            return $this->mark->isMarked();
        }

        return $this->mark->isMarkedForCurrentScope() || $this->mark->wasMarkedSinceCurrentWrite();
    }

    /** @return 'unstorable'|null */
    private function reasonFor(ResourceObject $ro, bool $marked): string|null
    {
        if ($marked) {
            return self::REASON;
        }

        $name = Header::findKey($ro->headers, Header::CACHE_CONTROL);
        $cacheControl = $name === null ? '' : $ro->headers[$name];
        if ($cacheControl === '' || $cacheControl === ($this->declaredCacheControl)($ro)) {
            return null;
        }

        $lower = strtolower($cacheControl);
        if (str_contains($lower, 'no-store') || str_contains($lower, 'private')) {
            return self::REASON;
        }

        return null;
    }
}
