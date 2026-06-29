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
     * Save Etag
     */
    public function saveEtag(AbstractUri $uri, string $etag, string $surrogateKeys, int|null $ttl): void;

    /**
     * Delete this URI's ETag entries — etag pool only, not the body, CDN, or dependents
     *
     * Used on the write/put path: a re-cached resource must not keep its old ETag (which is
     * keyed by ETag value, so a new ETag does not overwrite it) or it would serve a stale
     * 304. The body is overwritten by the following save; the CDN and dependents are the
     * concern of invalidateUri(), used by an explicit purge where content actually changed.
     */
    public function deleteEtag(AbstractUri $uri): bool;

    /**
     * Fully invalidate everything tagged with this URI: the body, its ETag, and every
     * dependent (parent) cache, plus the CDN. Used by an explicit purge / command, where
     * the content actually changed.
     */
    public function invalidateUri(AbstractUri $uri): InvalidateResult;

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
    public function saveDonut(AbstractUri $uri, ResourceDonut $donut, int|null $sMaxAge, array $headerKeys): void;

    /**
     * Save donut-cache state
     */
    public function saveDonutView(ResourceObject $ro, int|null $ttl): bool;

    /**
     * Invalidate tags
     *
     * Returns the per-target outcome (resource pool / ETag pool / CDN) rather than a
     * bare bool, so the logging decorator can report what actually happened. The CDN
     * purge error is carried on the result, not thrown, so logging can run first.
     *
     * @param list<string> $tags
     */
    public function invalidateTags(array $tags): InvalidateResult;
}
