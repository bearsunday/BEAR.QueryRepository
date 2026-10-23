<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Cdn\AkamaiCacheControlHeaderSetter;
use BEAR\QueryRepository\Cdn\FastlyCacheControlHeaderSetter;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

use function array_keys;
use function sprintf;
use function str_contains;
use function strtolower;

final readonly class HeaderSetter
{
    /**
     * Every header unset on an unstorable response (issue #222): the CDN-facing ones this
     * package's setters manage, across every flavor (default, Fastly, Akamai), plus
     * Cache-Control/Pragma themselves - so one the application set directly in onGet(),
     * bypassing the setters, does not survive the refusal under any header-name casing (RFC
     * 7230 §3.2: $ro->headers is a plain PHP array, not an HTTP-aware collection).
     */
    private const STRIPPED_HEADERS = [
        Header::CACHE_CONTROL,
        Header::PRAGMA,
        Header::CDN_CACHE_CONTROL,
        FastlyCacheControlHeaderSetter::CDN_CACHE_CONTROL_HEADER,
        AkamaiCacheControlHeaderSetter::CDN_CACHE_CONTROL_HEADER,
        Header::SURROGATE_KEY,
        AkamaiCacheControlHeaderSetter::PURGE_KEYS,
    ];

    public function __construct(
        private EtagSetterInterface $etagSetter,
        private UnstorableGateInterface $gate,
    ) {
    }

    /**
     * Set the validator and Cache-Control max-age, or - when the gate says this response is
     * unstorable for this request (issue #222) - strip every CDN-facing header and replace
     * Cache-Control/Pragma with ones that tell every cache, shared or private, not to keep it.
     * A Cache-Control value #[HttpCache] or #[NoHttpCache] itself declared is exempt from the
     * gate's no-store/private check: #[Cacheable] combined with #[HttpCache(noStore: true)]
     * still stores server-side while telling the client not to.
     */
    public function __invoke(ResourceObject $ro, int|null $cacheControlMaxAge, HttpCache|null $httpCache, int|null $time = null): void
    {
        if ($this->gate->reason($ro) !== null) {
            $this->stripHeaders($ro);
            $ro->headers[Header::CACHE_CONTROL] = 'private, no-store';
            $ro->headers[Header::PRAGMA] = 'no-cache';

            return;
        }

        ($this->etagSetter)($ro, $time, $httpCache);

        if ($cacheControlMaxAge !== null && $cacheControlMaxAge > 0) {
            $this->setCacheControlMaxAge($ro, $cacheControlMaxAge);
        }
    }

    private function stripHeaders(ResourceObject $ro): void
    {
        foreach (self::STRIPPED_HEADERS as $canonical) {
            foreach (array_keys($ro->headers) as $name) {
                if (strtolower($name) === strtolower($canonical)) {
                    unset($ro->headers[$name]);
                }
            }
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
