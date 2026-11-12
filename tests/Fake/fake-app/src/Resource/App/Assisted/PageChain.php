<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Assisted;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** page <- uncached mid <- uncached reader */
#[Cacheable]
class PageChain extends ResourceObject
{
    #[Embed(rel: 'mid', src: 'app://self/assisted/mid')]
    public function onGet(): static
    {
        $this->body['page'] = true;

        return $this;
    }
}
