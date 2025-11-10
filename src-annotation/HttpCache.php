<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use BEAR\QueryRepository\HttpCacheInterceptor;

use function implode;
use function sprintf;

/**
 * HTTP Cache Control
 *
 * Builds a complex Cache-Control header
 *
 * Example:
 * ```php
 * // CDN cache for 1 hour, browser cache for 5 minutes
 * #[HttpCache(maxAge: 300, sMaxAge: 3600)]
 *
 * // Private cache with revalidation
 * #[HttpCache(isPrivate: true, mustRevalidate: true, maxAge: 60)]
 *
 * // For no caching, use #[NoHttpCache] instead
 * ```
 *
 * Interceptors bound:
 * - HttpCacheInterceptor (onGet methods)
 *
 * @see HttpCacheInterceptor
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class HttpCache extends AbstractCacheControl
{
    /** @param array<string> $etag */
    public function __construct(
        /**
         * Is private cache
         *
         * true: Indicates that the response is intended for a single user and must not be stored by a shared cache. A private cache may store the response.
         * false: Indicates that the response may be cached by any cache.
         */
        public bool $isPrivate = false,
        /**
         * No cache without validation
         *
         * Forces caches to submit the request to the origin server for validation before releasing a cached copy.
         * This is *not* no-cache flag.
         */
        public bool $noCache = false,
        /**
         * No Store
         *
         * The cache should not store anything about the client request or server response.
         */
        public bool $noStore = false,
        /**
         * Must revalidate when cache is expired
         *
         * The cache must verify the status of the stale resources before using it and expired ones should not be used.
         */
        public bool $mustRevalidate = false,
        /**
         * Max time
         *
         * Specifies the maximum amount of time a resource will be considered fresh. Contrary to Expires, this directive is relative to the time of the request.
         */
        public int $maxAge = 0,
        /**
         * Shared cache max time
         *
         * Takes precedence over max-age or the Expires header, but it only applies to shared caches (e.g., proxies) and is ignored by a private cache.
         */
        public int $sMaxAge = 0,
        /**
         * Resource body index of Etag
         */
        public array $etag = [],
    ) {
    }

    public function __toString(): string
    {
        $control = [];
        if ($this->isPrivate) {
            $control[] = 'private';
        }

        if ($this->noCache) {
            $control[] = 'no-cache';
        }

        if ($this->noStore) {
            $control[] = 'no-store';
        }

        if ($this->mustRevalidate) {
            $control[] = 'must-revalidate';
        }

        if ($this->maxAge) {
            $control[] = sprintf('max-age=%d', $this->maxAge);
        }

        if ($this->sMaxAge) {
            $control[] = sprintf('s-maxage=%d', $this->sMaxAge);
        }

        return implode(', ', $control);
    }
}
