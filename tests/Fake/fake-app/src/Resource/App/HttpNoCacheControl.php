<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\HttpNoCache;
use BEAR\Resource\ResourceObject;

/**
 * @HttpNoCache
 */
class HttpNoCacheControl extends ResourceObject
{
    public function onGet() : ResourceObject
    {
        return $this;
    }
}
