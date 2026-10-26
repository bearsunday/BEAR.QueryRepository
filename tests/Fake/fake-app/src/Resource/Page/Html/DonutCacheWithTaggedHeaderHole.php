<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] page that embeds TaggedHeaderDefaultChild as a pure hole - onGet() never
 * reads it, so a real material signal would be absent; the header the child declares as a
 * property default is what trips the misclassification (see TaggedHeaderDefaultChild).
 */
#[DonutCache]
class DonutCacheWithTaggedHeaderHole extends ResourceObject
{
    #[Embed(rel: 'tagged', src: 'page://self/html/tagged-header-default-child')]
    public function onGet(): static
    {
        $this->body['article'] = '1';

        return $this;
    }
}
