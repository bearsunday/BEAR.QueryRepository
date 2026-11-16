<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;
use Override;

use function is_string;

#[Cacheable]
class CacheableOverTemplateDeclaringMiddle extends ResourceObject
{
    #[Embed(rel: 'middle', src: 'page://self/html/template-declaring-middle')]
    public function onGet(): static
    {
        return $this;
    }

    #[Override]
    public function toString(): string
    {
        if (is_string($this->view)) {
            return $this->view;
        }

        $this->view = (string) $this->body['middle'];

        return $this->view;
    }
}
