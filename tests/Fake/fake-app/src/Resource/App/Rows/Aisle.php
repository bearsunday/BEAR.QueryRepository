<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** An uncached resource that embeds and reads a cached one */
class Aisle extends ResourceObject
{
    #[Embed(rel: 'inner', src: 'app://self/rows/shelf')]
    public function onGet(): static
    {
        $this->body['read'] = (string) $this->body['inner'];

        return $this;
    }
}
