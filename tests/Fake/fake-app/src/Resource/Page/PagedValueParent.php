<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** Embeds a variant of PagedValue, whose onPut() has no #[Purge]: only the automatic purge reaches this page. */
#[Cacheable]
class PagedValueParent extends ResourceObject
{
    #[Embed(rel: 'child', src: 'app://self/paged-value?page=2')]
    public function onGet(): static
    {
        $this->body['time'] = \microtime(true);

        return $this;
    }
}
