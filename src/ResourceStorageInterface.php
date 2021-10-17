<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;

interface ResourceStorageInterface
{
    /**
     * Is ETag registered ?
     */
    public function hasEtag(string $etag): bool;

    /**
     * Update or save new Etag
     *
     * @return void
     */
    public function updateEtag(AbstractUri $uri, string $etag, string $surrogateKeys, ?int $ttl);

    /**
     * Delete Etag
     *
     * @return bool
     */
    public function deleteEtag(AbstractUri $uri);

    /**
     * Return cached resource state
     */
    public function get(AbstractUri $uri): ?ResourceState;

    /**
     * Save resource cache with value
     *
     * @return bool
     */
    public function saveValue(ResourceObject $ro, int $ttl);

    /**
     * Save resource cache with view
     *
     * @return bool
     */
    public function saveView(ResourceObject $ro, int $ttl);

    /**
     * Return cached resource static
     */
    public function getDonut(AbstractUri $uri): ?ResourceDonut;

    /**
     * Save donut-cacheable page
     */
    public function saveDonut(AbstractUri $uri, ResourceDonut $donut, ?int $sMaxAge): void;

    /**
     * Save donut-cache state
     */
    public function saveDonutView(ResourceObject $ro, ?int $ttl): bool;

    /**
     * Delete donut-cacheable page
     */
    public function deleteDonut(AbstractUri $uri): void;

    /**
     * Invalidate tags
     *
     * @param list<string> $tags
     */
    public function invalidateTags(array $tags): bool;
}
