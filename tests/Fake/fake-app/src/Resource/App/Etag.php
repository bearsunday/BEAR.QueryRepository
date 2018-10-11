<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable
 * @HttpCache(etag={"updated_at"})
 */
class Etag extends ResourceObject
{
    public function onGet(string $updatedAt = '0') : ResourceObject
    {
        $this->body['updated_at'] = $updatedAt;

        return $this;
    }
}
