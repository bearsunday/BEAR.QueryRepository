<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/** A #[Cacheable] collection whose handler is spelled onPOST and does not carry onGet's required parameter */
#[Cacheable]
class CasePostWriter extends ResourceObject
{
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id];

        return $this;
    }

    public function onPOST(string $title): static
    {
        $this->body = ['title' => $title];

        return $this;
    }
}
