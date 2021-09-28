<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class LevelTwo extends ResourceObject
{
    public $body = ['level-two' => 1];

    public function onGet()
    {
        return $this;
    }
}
