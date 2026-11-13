<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** An uncached page with a body of its own before it runs, over a cached resource */
class UncachedMiddle extends ResourceObject
{
    /** @var array<string, mixed> */
    public $body = ['middle' => 'shown'];

    #[Embed(rel: 'leaf', src: 'app://self/versioned-leaf')]
    public function onGet(): static
    {
        return $this;
    }
}
