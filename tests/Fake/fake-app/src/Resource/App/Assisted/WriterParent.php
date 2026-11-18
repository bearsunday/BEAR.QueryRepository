<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Assisted;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A #[Cacheable] page embedding the uncached writer */
#[Cacheable]
class WriterParent extends ResourceObject
{
    #[Embed(rel: 'writer', src: 'app://self/assisted/writer{?id}')]
    public function onGet(string $id): static
    {
        $this->body['id'] = $id;

        return $this;
    }
}
