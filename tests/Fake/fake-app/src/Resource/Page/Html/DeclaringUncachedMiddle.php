<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** UncachedMiddle that declares a dependency of its own */
class DeclaringUncachedMiddle extends ResourceObject
{
    /** @var array<string, mixed> */
    public $body = ['middle' => 'shown'];

    #[DependsOn(uri: 'app://self/middle-table')]
    #[Embed(rel: 'leaf', src: 'app://self/versioned-leaf')]
    public function onGet(): static
    {
        return $this;
    }
}
