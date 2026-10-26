<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A #[CacheableResponse] page whose onGet() reads a mutable embedded child's value into
 * another body key, per decision #2 - the embed slot itself stays untouched, so the child
 * is also rendered as the template's own hole.
 */
#[CacheableResponse]
class CacheableResponseWithMaterial extends ResourceObject
{
    #[Embed(rel: 'comment', src: 'page://self/html/mutable-comment')]
    public function onGet(): static
    {
        $this->body['article'] = '1';
        $this->body['commentText'] = (string) $this->body['comment'];
        $this->headers[Header::SURROGATE_KEY] = 'cacheable-response-with-material-page';

        return $this;
    }
}
