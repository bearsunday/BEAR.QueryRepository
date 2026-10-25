<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] page whose only #[Embed] is a self-link (`_self`) - EmbedInterceptor's
 * linkSelf() path, which never touches `$ro->body` at all. declaredEmbedRels() filters
 * `_self` out, so this is the one real shape where EmbedOnceInterceptor::swap() reaches
 * its is_array($body) fallback (as opposed to the declared-rel loop) with a genuinely
 * null body if nothing else has run yet.
 */
#[DonutCache]
class SelfLinkOnlyDonut extends ResourceObject
{
    #[Embed(rel: '_self', src: 'page://self/html/self-link-only-donut')]
    public function onGet(): static
    {
        return $this;
    }
}
