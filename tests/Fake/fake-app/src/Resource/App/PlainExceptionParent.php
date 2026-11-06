<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class PlainExceptionParent extends ResourceObject
{
    #[Embed(src: 'app://self/plain-exception-child', rel: 'child')]
    public function onGet(): ResourceObject
    {
        return $this;
    }
}
