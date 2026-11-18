<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceObject;

/** A #[CacheableResponse] page whose handler is spelled onPOST and does not carry onGet's required parameter */
#[CacheableResponse]
class CasePostDonutWriter extends ResourceObject
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
