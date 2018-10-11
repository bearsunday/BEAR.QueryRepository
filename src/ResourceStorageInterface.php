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
    public function setEtag(string $etag);

    public function hasEtag(string $etag) : bool;

    public function updateEtag(ResourceObject $ro);

    public function get(AbstractUri $uri);

    public function saveValue(ResourceObject $ro, int $ttl);

    public function saveView(ResourceObject $ro, int $ttl);

    public function delete(AbstractUri $uri) : bool;
}
