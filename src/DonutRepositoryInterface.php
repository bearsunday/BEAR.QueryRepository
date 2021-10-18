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
    public function get(ResourceObject $ro): ?ResourceObject;

    /**
     * Create donut-caching
     *
     * @param ResourceObject $ro      request invoked ResourceObject
     * @param ?int           $ttl     TTL for the donut (not for donut-hole)
     * @param ?int           $sMaxAge TTL, used as `max-age` in CDN cache control
     *
     * @see https://www.computerworld.com/article/2833493/what-exactly-is-donut-caching-.html
     */
    public function put(ResourceObject $ro, ?int $ttl = null, ?int $sMaxAge = null): ResourceObject;

    /**
     * Purge donut caching
     */
    public function purge(AbstractUri $uri): void;

    /**
     * @param list<string> $tags
     */
    public function invalidateTags(array $tags): void;
}
