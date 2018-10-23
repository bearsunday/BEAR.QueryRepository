<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

/**
 * HTTP Cache Control
 *
 * Builds a complex Cache-Control header
 *
 * @Annotation
 * @Target("CLASS")
 * {@inheritdoc}
 */
final class HttpCache extends AbstractCacheControl
{
    /**
     * Is private cache
     *
     * true: Indicates that the response is intended for a single user and must not be stored by a shared cache. A private cache may store the response.
     * false: Indicates that the response may be cached by any cache.
     *
     * @var bool
     */
    public $isPrivate = false;

    /**
     * No cache without validation
     *
     * Forces caches to submit the request to the origin server for validation before releasing a cached copy.
     * This is *not* no-cache flag.
     *
     * @var bool
     */
    public $noCache = false;

    /**
     * No Store
     *
     * The cache should not store anything about the client request or server response.
     *
     * @var bool
     */
    public $noStore = false;

    /**
     * Must revalidate when cache is expired
     *
     * The cache must verify the status of the stale resources before using it and expired ones should not be used.
     *
     * @var bool
     */
    public $mustRevalidate = false;

    /**
     * Max time
     *
     * Specifies the maximum amount of time a resource will be considered fresh. Contrary to Expires, this directive is relative to the time of the request.
     *
     * @var int
     */
    public $maxAge;

    /**
     * Shared cache max time
     *
     * Takes precedence over max-age or the Expires header, but it only applies to shared caches (e.g., proxies) and is ignored by a private cache.
     *
     * @var int
     */
    public $sMaxAge;

    /**
     * Resource body index of Etag
     *
     * @var array
     */
    public $etag = [];

    public function __toString()
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
            $control[] = \sprintf('max-age=%d', $this->maxAge);
        }
        if ($this->sMaxAge) {
            $control[] = \sprintf('s-maxage=%d', $this->sMaxAge);
        }

        return  \implode(', ', $control);
    }
}
