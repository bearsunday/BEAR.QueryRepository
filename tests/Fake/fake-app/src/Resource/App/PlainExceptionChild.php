<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

class PlainExceptionChild extends ResourceObject
{
    #[Embed(src: 'app://self/plain-exception-grandchild', rel: 'grandchild')]
    public function onGet(): ResourceObject
    {
        return $this;
    }
}
