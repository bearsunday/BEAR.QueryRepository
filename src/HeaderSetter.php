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
     * The CDN-facing headers this package's setters write, lifetime and purge keys. A custom
     * CdnCacheControlHeaderSetterInterface that writes another header name is not among them.
     */
    public const CDN_FACING_HEADERS = [
        Header::CDN_CACHE_CONTROL,
        FastlyCacheControlHeaderSetter::CDN_CACHE_CONTROL_HEADER,
        AkamaiCacheControlHeaderSetter::CDN_CACHE_CONTROL_HEADER,
        ...self::CDN_KEY_HEADERS,
    ];

    /** The headers a CDN indexes responses under for key-based purging */
    public const CDN_KEY_HEADERS = [
        Header::SURROGATE_KEY,
        AkamaiCacheControlHeaderSetter::PURGE_KEYS,
    ];

    public function __construct(
        private EtagSetterInterface $etagSetter,
    ) {
    }

    /**
     * Set the validator and, when a directive is given, the client Cache-Control; strip the CDN-facing headers instead
     * when the write found the response unstorable
     *
     * @param string|null $unstorable DeclaredCacheControl::reason(), decided once by the write
     */
    public function __invoke(ResourceObject $ro, string|null $unstorable, string|null $cacheControl, HttpCache|null $httpCache, int|null $time = null): void
    {
        if ($unstorable !== null) {
            $this->strip($ro);

            return;
        }

        ($this->etagSetter)($ro, $time, $httpCache);
        if ($cacheControl !== null) {
            $this->addCacheControl($ro, $cacheControl);
        }
    }

    /**
     * Strip every CDN-facing header from a response DeclaredCacheControl::reason() refuses. Its own private/no-store
     * Cache-Control is left as it is.
     */
    public function strip(ResourceObject $ro): void
    {
        $this->stripHeaders($ro, self::CDN_FACING_HEADERS);
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
