<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

/**
 * Finalizes response headers for a donut write/refresh: HeaderSetter (validator, Cache-Control)
 * plus the CDN lifetime, bundled because DonutRepository needs both at every write site and
 * this keeps its own constructor from growing a parameter per header concern (PHPMD
 * ExcessiveParameterList).
 */
final readonly class DonutHeaderSetter
{
    public function __construct(
        private HeaderSetter $headerSetter,
        private CdnCacheControlHeaderSetterInterface $cdnCacheControlHeaderSetter,
        private UnstorableGateInterface $gate,
    ) {
    }

    public function __invoke(ResourceObject $ro, int|null $cacheControlMaxAge, HttpCache|null $httpCache, int|null $time = null): void
    {
        ($this->headerSetter)($ro, $cacheControlMaxAge, $httpCache, $time);
    }

    /**
     * Hand the CDN a lifetime for 200 that is also storable
     *
     * The setters do not read the status code and the Fastly and Akamai defaults are
     * 31_536_000 seconds - a year - so an unguarded call would tell every shared cache to
     * hold a 301 or a 204 for that long. A response the gate marks unstorable (#222)
     * is refused the same way: the gate sits here rather than in the setters so both checks
     * also cover an application's own CdnCacheControlHeaderSetterInterface.
     */
    public function setCdnCacheControl(ResourceObject $ro, int|null $sMaxAge): void
    {
        if ($ro->code !== 200 || $this->gate->reason($ro) !== null) {
            return;
        }

        ($this->cdnCacheControlHeaderSetter)($ro, $sMaxAge);
    }
}
