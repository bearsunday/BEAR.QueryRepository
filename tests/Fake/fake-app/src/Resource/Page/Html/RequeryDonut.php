<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] page that reads its embedded child once, calls `withQuery()` on it, and
 * reads it again - both reads through `offsetGet()`, never a direct `($request)()` call.
 *
 * Proves `OnceRequest::withQuery()` discards both its own memo and `AbstractRequest`'s own
 * private `$result` memo (see `OnceRequest::resync()`): without clearing the latter too,
 * `AbstractRequest::invoke()` (which every `offsetGet()`/`__get()` call goes through)
 * would keep answering the pre-`withQuery()` ResourceObject forever and never call back
 * into `OnceRequest::__invoke()`.
 */
#[DonutCache]
class RequeryDonut extends ResourceObject
{
    #[Embed(rel: 'item', src: 'app://self/counter')]
    public function onGet(): static
    {
        $first = $this->body['item']['call'];
        $this->body['item']->withQuery(['tag' => 'requeried']);
        $second = $this->body['item']['call'];
        $secondTag = $this->body['item']['tag'];
        unset($this->body['item']);

        $this->body['first'] = $first;
        $this->body['second'] = $second;
        $this->body['secondTag'] = $secondTag;

        return $this;
    }
}
