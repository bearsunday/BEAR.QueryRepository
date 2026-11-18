<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

/** No cache attribute: its write names its own URI with #[Purge], the URI its own key already purges */
class SelfPurgeWriter extends ResourceObject
{
    public function onGet(string $id): static
    {
        $this->body = ['id' => $id];

        return $this;
    }

    #[Purge(uri: 'app://self/self-purge-writer{?id}')]
    public function onPut(string $id): static
    {
        unset($id);

        return $this;
    }
}
