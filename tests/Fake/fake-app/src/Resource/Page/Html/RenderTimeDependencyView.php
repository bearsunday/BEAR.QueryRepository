<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\CacheDependencyInterface;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use Override;

use function assert;
use function is_array;
use function is_string;

/** @see RenderTimeDependency */
#[Cacheable(type: 'view')]
class RenderTimeDependencyView extends ResourceObject
{
    public function __construct(
        private readonly ResourceInterface $resource,
        private readonly CacheDependencyInterface $cacheDependency,
    ) {
    }

    public function onGet(): static
    {
        $this->body = ['title' => 'render-time-dependency'];

        return $this;
    }

    #[Override]
    public function toString(): string
    {
        if (is_string($this->view)) {
            return $this->view;
        }

        $child = $this->resource->get('page://self/html/mutable-comment');
        $this->cacheDependency->depends($this, $child);
        assert(is_array($child->body));
        $this->view = 'comment:' . (string) $child->body['comment'];

        return $this->view;
    }
}
