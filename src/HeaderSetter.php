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
     * Set the validator and, when a directive is given, the client Cache-Control; strip every
     * CDN-facing header instead when the response's own Cache-Control already reads private or
     * no-store outside what #[HttpCache]/#[NoHttpCache] declares
     *
     * The developer-set private/no-store Cache-Control header itself is left as-is; only the
     * CDN-facing headers are stripped. A value #[HttpCache] or #[NoHttpCache] declared is
     * exempt from this check.
     */
    public function __invoke(ResourceObject $ro, string|null $cacheControl, HttpCache|null $httpCache, int|null $time = null): void
    {
        if ($this->declaredCacheControl->reason($ro) !== null) {
            $this->stripHeaders($ro, self::CDN_FACING_HEADERS);

            return;
        }

        ($this->etagSetter)($ro, $time, $httpCache);
        if ($cacheControl !== null) {
            $this->addCacheControl($ro, $cacheControl);
        }
    }

    /** The max-age directive for a lifetime, none when there is no lifetime to give */
    public function maxAge(int|null $seconds): string|null
    {
        return $seconds !== null && $seconds > 0 ? sprintf('max-age=%d', $seconds) : null;
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

    private function addCacheControl(ResourceObject $ro, string $directive): void
    {
        $existingName = Header::findKey($ro->headers, Header::CACHE_CONTROL);
        if ($existingName === null) {
            $ro->headers[Header::CACHE_CONTROL] = $directive;

            return;
        }

        // A lifetime already there stands, and no-store forbids keeping the response at all;
        // every case variant of the key is a Cache-Control the client receives
        foreach ($ro->headers as $name => $value) {
            if (strtolower($name) !== strtolower(Header::CACHE_CONTROL)) {
                continue;
            }

            $lower = strtolower($value);
            if (str_contains($lower, 'max-age') || str_contains($lower, 'no-store')) {
                return;
            }
        }

        $ro->headers[$existingName] .= ', ' . $directive;
    }
}
