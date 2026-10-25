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
     * The CDN-facing headers unset on an unstorable response (#222), across every
     * flavor (default, Fastly, Akamai) - unset unconditionally, whether the gate tripped on
     * a mark or on a Cache-Control the response set itself, since this package never hands a
     * shared cache a lifetime for a response it is itself refusing to store.
     */
    private const CDN_FACING_HEADERS = [
        Header::CDN_CACHE_CONTROL,
        FastlyCacheControlHeaderSetter::CDN_CACHE_CONTROL_HEADER,
        AkamaiCacheControlHeaderSetter::CDN_CACHE_CONTROL_HEADER,
        Header::SURROGATE_KEY,
        AkamaiCacheControlHeaderSetter::PURGE_KEYS,
    ];

    public function __construct(
        private EtagSetterInterface $etagSetter,
        private UnstorableGateInterface $gate,
        private UncacheableMarkInterface $mark,
    ) {
    }

    /**
     * Set the validator and Cache-Control max-age, or - when the gate says this response is
     * unstorable for this request (#222) - strip every CDN-facing header under any
     * casing (RFC 7230 §3.2: $ro->headers is a plain PHP array, not an HTTP-aware collection).
     *
     * A producer that called UncacheableMarkInterface::mark() also gets Cache-Control/Pragma
     * replaced with no-store directives, since the response was never meant to carry a cache
     * directive of its own; a response refused only for its own `private`/`no-store`
     * Cache-Control (no mark) keeps exactly what the developer wrote, since the gate controls
     * server-side storage, not what the client is told. A Cache-Control value #[HttpCache] or
     * #[NoHttpCache] declared is exempt from this check either way, so #[Cacheable] combined
     * with #[HttpCache(noStore: true)] still stores server-side while telling the client not to.
     */
    public function __invoke(ResourceObject $ro, int|null $cacheControlMaxAge, HttpCache|null $httpCache, int|null $time = null): void
    {
        if ($this->gate->reason($ro) !== null) {
            $this->stripHeaders($ro, self::CDN_FACING_HEADERS);
            if ($this->mark->isMarked()) {
                $this->stripHeaders($ro, [Header::CACHE_CONTROL, Header::PRAGMA]);
                $ro->headers[Header::CACHE_CONTROL] = 'private, no-store';
                $ro->headers[Header::PRAGMA] = 'no-cache';
            }

            return;
        }

        ($this->etagSetter)($ro, $time, $httpCache);

        if ($cacheControlMaxAge !== null && $cacheControlMaxAge > 0) {
            $this->setCacheControlMaxAge($ro, $cacheControlMaxAge);
        }
    }

    /** @param list<string> $canonicalNames */
    private function stripHeaders(ResourceObject $ro, array $canonicalNames): void
    {
        foreach ($canonicalNames as $canonical) {
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
        $existingName = Header::findKey($ro->headers, Header::CACHE_CONTROL);
        if ($existingName === null) {
            $ro->headers[Header::CACHE_CONTROL] = $setMaxAge;

            return;
        }

        if (str_contains($ro->headers[$existingName], 'max-age')) {
            return;
        }

        $ro->headers[$existingName] .= ', ' . $setMaxAge;
    }
}
