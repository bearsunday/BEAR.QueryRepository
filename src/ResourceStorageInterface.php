<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;

interface ResourceStorageInterface
{
    /**
     * The entry this request would be served from, when it still carries one of these validators
     *
     * With $uri, only a validator issued for that URI is looked up.
     *
     * @param string $ifNoneMatch If-None-Match field value: quoted entity-tag(s), weak validator, or bare legacy token
     */
    public function validatedEntry(string $ifNoneMatch, AbstractUri|null $uri = null): ResourceState|null;

    /**
     * Return cached resource state
     */
    public function get(AbstractUri $uri): ResourceState|null;

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
    public function getDonut(AbstractUri $uri): ResourceDonut|null;

    /**
     * Save donut-cacheable page
     *
     * @param list<string> $headerKeys
     */
    public function saveDonut(AbstractUri $uri, ResourceDonut $donut, int|null $ttl, array $headerKeys): bool;

    /**
     * Save donut-cache state
     *
     * @param list<string>|null $tags surrogate keys to store under; null reads the Surrogate-Key header
     */
    public function saveDonutView(ResourceObject $ro, int|null $ttl, array|null $tags = null): bool;

    /**
     * Invalidate tags
     *
     * @param list<string> $tags
     */
    public function invalidateTags(array $tags): bool;
}
