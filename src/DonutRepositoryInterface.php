<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;

interface DonutRepositoryInterface
{
    public function get(ResourceObject $ro): ResourceObject|null;

    /**
     * @param ResourceObject $ro      request invoked ResourceObject
     * @param ?int           $ttl     TTL for the donut (not for donut-hole)
     * @param ?int           $sMaxAge TTL, used as `max-age` in CDN cache control
     *
     * @see https://www.computerworld.com/article/2833493/what-exactly-is-donut-caching-.html
     */
    public function putStatic(ResourceObject $ro, int|null $ttl = null, int|null $sMaxAge = null): ResourceObject;

    public function putDonut(ResourceObject $ro, int|null $donutTtl): ResourceObject;

    public function purge(AbstractUri $uri): void;

    /** @param list<string> $tags */
    public function invalidateTags(array $tags): void;
}
