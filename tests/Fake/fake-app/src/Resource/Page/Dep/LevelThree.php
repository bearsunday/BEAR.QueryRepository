<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Cacheable;
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

    // A write busts this leaf's cache — RefreshSameCommand purges the written resource
    // itself and re-runs onGet to refresh it, and the surrogate-key cascade invalidates
    // its dependents (level-two, level-one). That is the command-driven invalidation
    // demonstrated in demo/run-dependency.php; a #[Purge] aimed at this same URI would
    // only run after that refresh and delete the entry it had just repopulated.
    public function onPut()
    {
        return $this;
    }
}
