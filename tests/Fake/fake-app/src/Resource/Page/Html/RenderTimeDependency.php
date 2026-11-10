<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\CacheDependencyInterface;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use Override;

use function assert;
use function is_array;
use function is_string;

/**
 * Pulls its child while rendering and declares the dependency, as a template helper does
 */
#[CacheableResponse]
class RenderTimeDependency extends ResourceObject
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
