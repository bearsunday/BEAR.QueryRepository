<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\Page;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable
 */
class Index extends ResourceObject
{
    public function onGet()
    {
        $this['time'] = \microtime(true);

        return $this;
    }
}
