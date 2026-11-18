<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A #[Cacheable] page embedding the receipt writer */
#[Cacheable]
class ReceiptWriterParent extends ResourceObject
{
    #[Embed(rel: 'writer', src: 'app://self/receipt-writer{?id}')]
    public function onGet(int $id): static
    {
        $this->body['id'] = $id;

        return $this;
    }
}
