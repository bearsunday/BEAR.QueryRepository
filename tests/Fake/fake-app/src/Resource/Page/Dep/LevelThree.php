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

    // A write purges this leaf's own key and re-runs onGet to refresh it, and the surrogate-key
    // cascade invalidates its dependents (level-two, level-one), as demo/run-dependency.php shows.
    public function onPut()
    {
        return $this;
    }
}
