<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable(type="view")
 */
#[Cacheable(type: "view")]
class NullView extends ResourceObject
{
    public function onGet()
    {
        return $this;
    }
}
