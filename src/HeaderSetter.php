<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

use function sprintf;
use function str_contains;

final readonly class HeaderSetter
{
    public function __construct(
        private EtagSetterInterface $etagSetter,
        private UnstorableGateInterface $gate,
    ) {
    }

    /**
     * Set the validator and Cache-Control max-age, or - when the gate says this response is
     * unstorable for this request (issue #222) - replace them with headers that tell every
     * cache, shared or private, not to keep it. A Cache-Control value #[HttpCache] itself
     * declared is exempt from the gate's no-store/private check: #[Cacheable] combined with
     * #[HttpCache(noStore: true)] still stores server-side while telling the client not to.
     */
    public function __invoke(ResourceObject $ro, int|null $cacheControlMaxAge, HttpCache|null $httpCache, int|null $time = null): void
    {
        $declaredCacheControl = $httpCache instanceof HttpCache ? (string) $httpCache : null;
        if ($this->gate->reason($ro, $declaredCacheControl) !== null) {
            $ro->headers[Header::CACHE_CONTROL] = 'private, no-store';
            $ro->headers[Header::PRAGMA] = 'no-cache';

            return;
        }

        ($this->etagSetter)($ro, $time, $httpCache);

        if ($cacheControlMaxAge !== null && $cacheControlMaxAge > 0) {
            $this->setCacheControlMaxAge($ro, $cacheControlMaxAge);
        }
    }

    private function setCacheControlMaxAge(ResourceObject $ro, int $age): void
    {
        $setMaxAge = sprintf('max-age=%d', $age);
        if (! isset($ro->headers[Header::CACHE_CONTROL])) {
            $ro->headers[Header::CACHE_CONTROL] = $setMaxAge;

            return;
        }

        if (str_contains($ro->headers[Header::CACHE_CONTROL], 'max-age')) {
            return;
        }

        $ro->headers[Header::CACHE_CONTROL] .= ', ' . $setMaxAge;
    }
}
