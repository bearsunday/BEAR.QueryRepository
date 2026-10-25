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

    /**
     * Clean up and refuse the response header the SAME way putStatic()/putDonut()'s own
     * cleanup+refusal would, for a $ro that is NOT going through either of them at all - a
     * no-op unless $ro is ALSO marked unstorable.
     *
     * AbstractDonutCacheInterceptor's own "already answered" shortcut (an ETag already on
     * $ro, or an error code) skips putStatic()/putDonut() entirely so a resource's own,
     * legitimately pre-set validator is never overwritten by the donut machinery - but that
     * ETag can also be a side effect of a direct, non-AOP QueryRepository::put() call made
     * from inside this page's own onGet() (Kimi's finding): doPut()'s own headerSetter sets
     * one whenever that direct write is not itself refused. If onGet() then marks the
     * response AFTER that direct put(), the shortcut's own early return left whatever the
     * direct put() already stored uncleaned and the response headers unrefused - this method
     * closes that gap without touching $ro at all when it turns out NOT to be unstorable.
     *
     * @return 'unstorable'|null
     */
    public function refuseIfUnstorable(ResourceObject $ro): string|null;
}
