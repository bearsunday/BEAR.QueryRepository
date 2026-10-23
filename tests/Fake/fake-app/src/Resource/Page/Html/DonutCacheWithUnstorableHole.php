<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] page whose embedded hole marks itself unstorable - the template must still
 * be saved: it holds only a placeholder for the hole, never the hole's content (issue #222).
 */
#[DonutCache]
class DonutCacheWithUnstorableHole extends ResourceObject
{
    #[Embed(rel: 'secret', src: 'app://self/unstorable-value')]
    public function onGet() : ResourceObject
    {
        $this->body['article'] = '1';

        return $this;
    }
}
