<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;

interface ResourceStorageInterface
{
    /**
     * Update or save new Etag
     */
    public function updateEtag(ResourceObject $ro);

    /**
     * Is ETag registered ?
     */
    public function hasEtag(string $etag) : bool;

    /**
     * Get resource cache
     *
     * @return [$uri, $code, $headers, $body, $view]]|false
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
