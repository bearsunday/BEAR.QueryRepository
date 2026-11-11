<?php

declare(strict_types=1);

namespace FakeVendor\DemoApp\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

use function mb_strtoupper;

#[Cacheable]
class ArticleSummary extends ResourceObject
{
    public function __construct(
        private readonly ResourceInterface $resource,
    ) {
    }

    /**
     * Reads app://self/article's title directly, not with #[Embed]: no ETag or Surrogate-Key
     * of its own would reach a page that embeds this resource, so #[DependsOn] tags it by hand
     */
    #[DependsOn(uri: 'app://self/article?id={id}')]
    public function onGet(int $id): static
    {
        $title = $this->resource->get('app://self/article', ['id' => $id])->body['title'];
        $this->body = ['id' => $id, 'summary' => mb_strtoupper((string) $title)];

        return $this;
    }
}
