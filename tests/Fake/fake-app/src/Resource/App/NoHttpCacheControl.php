<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\NoHttpCache;
use BEAR\Resource\ResourceObject;

#[NoHttpCache]
class NoHttpCacheControl extends ResourceObject
{
    public function onGet(int $code = 200) : ResourceObject
    {
        $this->code = $code;

        return $this;
    }
}
