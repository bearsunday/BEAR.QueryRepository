<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] page whose onGet() reads a mutable embedded child's value into another
 * body key - the child's value can change between requests, proving that purging the
 * material child serves genuinely fresh content, not just a fresh cache_miss shape.
 */
#[DonutCache]
class DonutCacheWithMutableMaterial extends ResourceObject
{
    #[Embed(rel: 'comment', src: 'page://self/html/mutable-comment')]
    public function onGet(): static
    {
        $this->body['article'] = '1';
        $this->body['commentText'] = (string) $this->body['comment'];

        return $this;
    }
}
