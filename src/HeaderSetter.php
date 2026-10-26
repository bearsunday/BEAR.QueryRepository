<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Cdn\AkamaiCacheControlHeaderSetter;
use BEAR\QueryRepository\Cdn\FastlyCacheControlHeaderSetter;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;
use LogicException;

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
    ) {
    }

    /**
     * Sets the validator and Cache-Control max-age. When `$unstorable` is given, strips the
     * CDN-facing headers; `$wasMarked` must come from the same predicate that decided
     * `$unstorable`, or an unrelated mark could override a resource's own `private` header.
     * A declared `#[HttpCache]` value is exempt.
     */
    public function __invoke(ResourceObject $ro, int|null $cacheControlMaxAge, HttpCache|null $httpCache, int|null $time = null, string|null $unstorable = null, bool|null $wasMarked = null): void
    {
        if ($unstorable !== null) {
            if ($wasMarked === null) {
                throw new LogicException('HeaderSetter::__invoke() requires $wasMarked whenever $unstorable is given - compute it with the SAME predicate that decided $unstorable (UnstorableGateInterface::isMarkReasonForOwnWrite() or ::isMarkReason()) and pass it, rather than leaving this class to guess.');
            }

            $this->stripHeaders($ro, self::CDN_FACING_HEADERS);
            if ($wasMarked) {
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
