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

/** @internal */
final readonly class HeaderSetter
{
    /**
     * The CDN-facing headers unset on an unstorable response, across every flavor (default,
     * Fastly, Akamai) - unset unconditionally, since this package never hands a shared cache
     * a lifetime for a response it is itself refusing to store.
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
        private DeclaredCacheControl $declaredCacheControl = new DeclaredCacheControl(),
    ) {
    }

    /**
     * Set the validator and Cache-Control max-age, or strip every CDN-facing header when
     * the response's own Cache-Control already reads private or no-store outside what
     * #[HttpCache]/#[NoHttpCache] declares
     *
     * The developer-set private/no-store Cache-Control header itself is left as-is; only the
     * CDN-facing headers are stripped. A value #[HttpCache] or #[NoHttpCache] declared is
     * exempt from this check.
     */
    public function __invoke(ResourceObject $ro, int|null $cacheControlMaxAge, HttpCache|null $httpCache, int|null $time = null): void
    {
        if ($this->declaredCacheControl->reason($ro) !== null) {
            $this->stripHeaders($ro, self::CDN_FACING_HEADERS);

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

        // no-store forbids keeping the response at all, so a lifetime beside it is contradictory
        $existing = strtolower($ro->headers[$existingName]);
        if (str_contains($existing, 'max-age') || str_contains($existing, 'no-store')) {
            return;
        }

        $ro->headers[$existingName] .= ', ' . $setMaxAge;
    }
}
