<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

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
     * @param ResourceObject $ro       request invoked ResourceObject
     * @param ?int           $sMaxAge  TTL, used as `max-age` in CDN cache control
     * @param ?int           $donutAge TTL for the donut (not for donut-hole)
     *
     * @see https://www.computerworld.com/article/2833493/what-exactly-is-donut-caching-.html
     */
    public function createDonut(ResourceObject $ro, ?int $sMaxAge = null, ?int $donutAge = null): ResourceObject;
}
