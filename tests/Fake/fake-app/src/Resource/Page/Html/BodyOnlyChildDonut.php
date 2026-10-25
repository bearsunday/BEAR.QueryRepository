<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] page with two embeds, neither ever rendered as a hole (issue #226):
 *
 * - `category` is read into `description` in onGet(), then `unset()` from the body - the
 *   case the registry (`DonutChildExecutionsInterface`) exists for: by the time the
 *   template is built, `$ro->body` no longer holds any trace it ever existed.
 * - `unused` is declared but never touched at all - onGet() never reads it, the template
 *   never renders it - proving it contributes no tag and, more importantly, never runs
 *   ("never execute a child just to tag it").
 */
#[DonutCache]
class BodyOnlyChildDonut extends ResourceObject
{
    #[Embed(rel: 'category', src: 'page://self/html/comment')]
    #[Embed(rel: 'unused', src: 'app://self/counter')]
    public function onGet(): static
    {
        $this->body['description'] = $this->body['category']['comment'];
        unset($this->body['category']);

        return $this;
    }
}
