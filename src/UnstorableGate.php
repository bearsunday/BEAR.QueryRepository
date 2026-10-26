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
 * no-store, or it contains private (header name and directives matched case-insensitively,
 * RFC 7230 §3.2/RFC 9111 §5.2) - private because this package is a shared cache in the sense
 * of RFC 9111: whatever it stores is served to every later visitor. `isPrivate`/`noStore`
 * applied by `#[HttpCache]` or `#[NoHttpCache]` is unaffected: a header that still reads
 * exactly what DeclaredCacheControl computes for this resource is exempt from the
 * no-store/private check.
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
     * Only trusts the own-write-scoped predicate (isMarkedForCurrentScope() ||
     * wasMarkedSinceCurrentWrite()) when the innermost open write actually belongs to $ro, by
     * object identity (CacheInterceptor's own opened scope, isCurrentWriteFor()) - otherwise
     * (a direct, non-AOP put(), or one nested inside a DIFFERENT resource's own write) falls
     * back to the conservative, any-owner isMarked() instead, which can only over-refuse,
     * never leak past a mark that write-scope tracking was never actually watching for THIS
     * write (#234/#222, see UncacheableMarkInterface's own docblock on isCurrentWriteFor()).
     */
    #[Override]
    public function reasonForOwnWrite(ResourceObject $ro): string|null
    {
        return $this->reasonFor($ro, $this->isMarkReasonForOwnWrite($ro));
    }

    /**
     * {@inheritDoc}
     *
     * The exact same any-owner predicate reason() itself trusts - exposed separately, for the
     * same reason isMarkReasonForOwnWrite() is: a caller already holding reason()'s own
     * 'unstorable'|null result (DonutHeaderSetter, passing it to HeaderSetter as $unstorable)
     * needs the SAME predicate, not HeaderSetter re-deriving its own, to tell a mark apart
     * from a Cache-Control-only refusal - see isMarkReasonForOwnWrite()'s own docblock for
     * the own-write-scoped twin; HeaderSetter itself holds no UncacheableMarkScopeInterface
     * of its own, so it cannot derive either answer without being handed it.
     */
    #[Override]
    public function isMarkReason(ResourceObject $ro): bool
    {
        return $this->mark->isMarked();
    }

    /**
     * {@inheritDoc}
     *
     * The exact same own-write-scoped-or-any-owner-fallback predicate reasonForOwnWrite()
     * itself trusts (see that method's own docblock) - exposed separately so a caller already
     * holding THAT method's own 'unstorable'|null result (HeaderSetter, handed it by
     * QueryRepository::doPut() instead of recomputing its own answer) can also tell a mark
     * apart from a Cache-Control-only refusal, using the SAME predicate reasonForOwnWrite()
     * used to decide 'unstorable' in the first place. Without this split, HeaderSetter's own
     * any-owner isMarked() would wrongly override a #[Cacheable] resource's own Cache-Control
     * header (e.g. `private, max-age=60`, no mark of its own) with `private, no-store`
     * whenever an UNRELATED mark happens anywhere else in the request.
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
