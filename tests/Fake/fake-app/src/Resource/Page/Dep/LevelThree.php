<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable
 */
#[Cacheable]
class LevelThree extends ResourceObject
{
    public $body = ['level-three' => 1];

    public function onGet()
    {
        return $this;
    }
}
