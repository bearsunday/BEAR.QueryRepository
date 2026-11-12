<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Assisted;

use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** An uncached resource that embeds and reads the uncached reader */
class Mid extends ResourceObject
{
    #[Embed(rel: 'inner', src: 'app://self/assisted/child')]
    public function onGet(): static
    {
        $this->body['read'] = (string) $this->body['inner'];

        return $this;
    }
}
