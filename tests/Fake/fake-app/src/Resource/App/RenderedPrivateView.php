<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;
use Override;

/** A #[Cacheable(type: 'view')] resource whose rendering, not onGet(), sets Cache-Control to private */
#[Cacheable(type: 'view')]
class RenderedPrivateView extends ResourceObject
{
    public function onGet(): ResourceObject
    {
        $this->body = ['name' => 'rendered'];

        return $this;
    }

    #[Override]
    public function toString(): string
    {
        $this->headers[Header::CACHE_CONTROL] = 'private';
        $this->view = 'rendered';

        return $this->view;
    }
}
