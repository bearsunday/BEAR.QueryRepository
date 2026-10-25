<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A #[CacheableResponse] page (the doPutStatic/putStatic path, not #[DonutCache]'s putDonut)
 * with one embed read into the body and never rendered as a hole - the doPutStatic
 * counterpart of BodyOnlyChildDonut, for proving the live response's own SurrogateKeys
 * (and so its saved ETag/view) carry that child's tag too (issue #226 review), not only
 * the stored template.
 */
#[CacheableResponse]
class BodyOnlyChildCacheableResponse extends ResourceObject
{
    #[Embed(rel: 'category', src: 'page://self/html/comment')]
    public function onGet(): static
    {
        $this->body['description'] = $this->body['category']['comment'];
        unset($this->body['category']);

        return $this;
    }
}
