<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable
 */
#[Cacheable]
class LevelOne extends ResourceObject
{
    public $body = ['level-one' => 1];

    /**
     * @Embed(rel="level-two", src="/dep/level-two")
     */
    #[Embed(rel: 'level-two', src: '/dep/level-two')]
    public function onGet()
    {
        return $this;
    }
}
