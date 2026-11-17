<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A #[DonutCache] part over a cached resource */
#[DonutCache]
class DonutPart extends ResourceObject
{
    #[Embed(rel: 'child', src: 'app://self/versioned-leaf')]
    public function onGet(): static
    {
        return $this;
    }
}
