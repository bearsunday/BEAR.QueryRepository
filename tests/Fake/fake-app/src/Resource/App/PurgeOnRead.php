<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

class PurgeOnRead extends ResourceObject
{
    #[Purge(uri: 'page://self/dep/level-one')]
    public function onGet(): static
    {
        return $this;
    }
}
