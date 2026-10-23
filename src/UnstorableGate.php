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
        private UncacheableMarkInterface $mark,
        private DeclaredCacheControl $declaredCacheControl,
    ) {
    }

    /** @return 'unstorable'|null */
    #[Override]
    public function reason(ResourceObject $ro): string|null
    {
        return $this->reasonFor($ro, $this->mark->isMarked());
    }

    /** @return 'unstorable'|null */
    #[Override]
    public function reasonExcludingHoleMarks(ResourceObject $ro): string|null
    {
        return $this->reasonFor($ro, $this->mark->isMarkedForCurrentScope());
    }

    /** @return 'unstorable'|null */
    private function reasonFor(ResourceObject $ro, bool $marked): string|null
    {
        if ($marked) {
            return self::REASON;
        }

        $cacheControl = $this->cacheControlHeader($ro);
        if ($cacheControl === '' || $cacheControl === ($this->declaredCacheControl)($ro)) {
            return null;
        }

        $lower = strtolower($cacheControl);
        if (str_contains($lower, 'no-store') || str_contains($lower, 'private')) {
            return self::REASON;
        }

        return null;
    }

    /**
     * Read Cache-Control by header name case-insensitively (RFC 7230 §3.2): $ro->headers is
     * a plain PHP array, not an HTTP-aware collection, so an application writing
     * `cache-control` would otherwise slip past this exact-key lookup.
     */
    private function cacheControlHeader(ResourceObject $ro): string
    {
        foreach ($ro->headers as $name => $value) {
            if (strtolower($name) === 'cache-control') {
                return $value;
            }
        }

        return '';
    }
}
