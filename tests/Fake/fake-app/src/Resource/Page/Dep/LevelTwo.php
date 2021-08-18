<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable
 */
#[Cacheable]
class LevelTwo extends ResourceObject
{
    public $body = ['level-two' => 1];

    /**
     * @Embed(rel="level-three", src="/dep/level-three")
     */
    #[Embed(rel: 'level-three', src: '/dep/level-three')]
    public function onGet()
    {
        return $this;
    }
}
