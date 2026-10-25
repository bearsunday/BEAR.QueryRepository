<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;

interface DonutRepositoryInterface
{
    /**
     * Return resource object from donut-caching
     */
    public function get(ResourceObject $ro): ResourceObject|null;

    /**
     * Create cacheable donut-caching
     *
     * The entire donut, including the donut and the hole, can be cached,
     * and cache headers such as Cdn-Cache-Control, ETag, and Age will be given.
     *
     * @param ResourceObject $ro      request invoked ResourceObject
     * @param ?int           $ttl     TTL for the donut (not for donut-hole)
     * @param ?int           $sMaxAge TTL, used as `max-age` in CDN cache control
     *
     * @see https://www.computerworld.com/article/2833493/what-exactly-is-donut-caching-.html
     */
    public function putStatic(ResourceObject $ro, int|null $ttl = null, int|null $sMaxAge = null): ResourceObject;

    /**
     * Create un-cacheable donut-caching
     *
     * The donut and the hole can be cached individually, but not the whole donut,
     * and cache headers such as Cdn-Cache-Control, ETag, and Age will not be given.
     */
    public function putDonut(ResourceObject $ro, int|null $donutTtl): ResourceObject;

    /**
     * Purge donut caching
     */
    public function purge(AbstractUri $uri): void;

    /** @param list<string> $tags */
    public function invalidateTags(array $tags): void;
}
