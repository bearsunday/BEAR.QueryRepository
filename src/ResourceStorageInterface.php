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
    public function hasEtag(string $etag) : bool;

    /**
     * Update or save new Etag
     */
    public function updateEtag(ResourceObject $ro, int $lifeTime);

    /**
     * Delete Etag
     */
    public function deleteEtag(AbstractUri $uri);

    /**
     * Get resource cache
     *
     * return [$uri, $code, $headers, $body, $view]] array.
     *
     * @return array|false
     */
    public function get(AbstractUri $uri);

    /**
     * Save resource cache with value
     */
    public function saveValue(ResourceObject $ro, int $ttl);

    /**
     * Save resource cache with view
     */
    public function saveView(ResourceObject $ro, int $ttl);

    /**
     * Delete resource cache
     */
    public function delete(AbstractUri $uri) : bool;
}
