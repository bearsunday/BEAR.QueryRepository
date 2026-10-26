<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] page whose onGet() reads its embedded child's value into another body
 * key, per decision #2 - the embed slot itself stays untouched, so the child is also
 * rendered as the template's own hole.
 */
#[DonutCache]
class DonutCacheWithMaterial extends ResourceObject
{
    #[Embed(rel: 'comment', src: 'page://self/html/comment')]
    public function onGet(): static
    {
        $this->body['article'] = '1';
        $this->body['commentText'] = (string) $this->body['comment'];

        return $this;
    }
}
