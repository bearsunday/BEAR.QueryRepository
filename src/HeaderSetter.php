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
use function trim;

/** @internal */
final readonly class HeaderSetter
{
    /** The headers that give a CDN a lifetime; a CDN given one ignores Cache-Control (RFC 9213 §2.2) */
    public const CDN_LIFETIME_HEADERS = [
        Header::CDN_CACHE_CONTROL,
        FastlyCacheControlHeaderSetter::CDN_CACHE_CONTROL_HEADER,
        AkamaiCacheControlHeaderSetter::CDN_CACHE_CONTROL_HEADER,
    ];

    /**
     * The CDN-facing headers this package's setters write, lifetime and purge keys. A custom
     * CdnCacheControlHeaderSetterInterface that writes another header name is not among them.
     */
    public const CDN_FACING_HEADERS = [
        ...self::CDN_LIFETIME_HEADERS,
        ...self::CDN_KEY_HEADERS,
    ];

    /** The headers a CDN indexes responses under for key-based purging */
    public const CDN_KEY_HEADERS = [
        Header::SURROGATE_KEY,
        AkamaiCacheControlHeaderSetter::PURGE_KEYS,
    ];

    /**
     * A write can replace a page at any time, so a browser revalidates it every time instead of reusing it for a
     * lifetime guessed from Last-Modified; a CDN follows its own lifetime
     */
    private const REVALIDATE = 'max-age=0';

    public function __construct(
        private EtagSetterInterface $etagSetter,
        private CdnCacheControlHeaderSetterInterface $cdnCacheControlHeaderSetter,
    ) {
    }

    /**
     * Strip as {@see template()} does, then, unless the response is unstorable, set a value or view entry's validator
     * and, when a directive is given, its client Cache-Control
     */
    public function entry(ResourceObject $ro, Storability $storability, string|null $cacheControl, HttpCache|null $httpCache): void
    {
        $this->template($ro, $storability);
        if ($storability !== Storability::Unstorable) {
            $this->setClientHeaders($ro, $cacheControl, null, $httpCache);
        }
    }

    /**
     * Set a page's CDN lifetime, validator and client Cache-Control, in that order. On a 200 the declared
     * Cache-Control replaces the one set, as HttpCacheInterceptor does; then max-age=0 is added unless the
     * Cache-Control has a max-age or no-store.
     *
     * The CDN setters do not read the status code and the Fastly and Akamai defaults are
     * 31_536_000 seconds - a year - so only a 200 is handed a lifetime. The check sits here
     * rather than in the setters so it also covers an application's own
     * CdnCacheControlHeaderSetterInterface.
     *
     * @return TagSet the Surrogate-Key header as composed, before a CDN setter can move it
     */
    public function page(ResourceObject $ro, Storability $storability, int|null $sMaxAge, string|null $declaredCacheControl, int|null $lastModified): TagSet
    {
        $surrogateKeys = TagSet::ofHeaders($ro->headers);
        match ($storability) {
            Storability::Unstorable => $this->strip($ro),
            Storability::DeclaredPrivate => $this->stripLifetime($ro),
            Storability::Shared => $this->shareLifetime($ro, $sMaxAge),
        };
        if ($storability !== Storability::Unstorable) {
            if ($declaredCacheControl !== null && $ro->code === 200) {
                $ro->headers[Header::CACHE_CONTROL] = $declaredCacheControl;
            }

            $this->setClientHeaders($ro, self::REVALIDATE, $lastModified, null);
        }

        return $surrogateKeys;
    }

    /** Strip the CDN-facing headers from an unstorable response, and the CDN lifetime from a declared-private one */
    public function template(ResourceObject $ro, Storability $storability): void
    {
        match ($storability) {
            Storability::Unstorable => $this->strip($ro),
            Storability::DeclaredPrivate => $this->stripLifetime($ro),
            Storability::Shared => null,
        };
    }

    /** The max-age directive for a lifetime, none when there is no lifetime to give */
    public function maxAge(int|null $seconds): string|null
    {
        return $seconds !== null && $seconds > 0 ? sprintf('max-age=%d', $seconds) : null;
    }

    private function setClientHeaders(ResourceObject $ro, string|null $cacheControl, int|null $lastModified, HttpCache|null $httpCache): void
    {
        ($this->etagSetter)($ro, $lastModified, $httpCache);
        if ($cacheControl !== null) {
            $this->addCacheControl($ro, $cacheControl);
        }
    }

    private function shareLifetime(ResourceObject $ro, int|null $sMaxAge): void
    {
        if ($ro->code === 200) {
            ($this->cdnCacheControlHeaderSetter)($ro, $sMaxAge);
        }
    }

    /** Its own private/no-store Cache-Control is left as it is */
    private function strip(ResourceObject $ro): void
    {
        $this->stripHeaders($ro, self::CDN_FACING_HEADERS);
    }

    /** Strip a CDN lifetime set by the resource or its renderer; its purge keys stay */
    private function stripLifetime(ResourceObject $ro): void
    {
        $this->stripHeaders($ro, self::CDN_LIFETIME_HEADERS);
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
        // An #[HttpCache] with only an etag declares an empty Cache-Control
        if ($existingName === null || trim($ro->headers[$existingName]) === '') {
            $ro->headers[$existingName ?? Header::CACHE_CONTROL] = $directive;

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
