<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class LevelThree extends ResourceObject
{
    public $body = ['level-three' => 1];

    public function onGet()
    {
        return $this;
    }

    // A write busts this leaf's cache; the surrogate-key cascade invalidates
    // its dependents (level-two, level-one) — the command-driven invalidation
    // demonstrated in demo/run-dependency.php.
    #[Purge(uri: 'page://self/dep/level-three')]
    public function onPut()
    {
        return $this;
    }
}
