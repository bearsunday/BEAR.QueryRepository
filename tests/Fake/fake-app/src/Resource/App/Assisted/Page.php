<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Assisted;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A #[Cacheable] page embedding an uncached reader */
#[Cacheable]
class Page extends ResourceObject
{
    #[Embed(rel: 'child', src: 'app://self/assisted/child')]
    public function onGet(): static
    {
        $this->body['page'] = true;

        return $this;
    }
}
