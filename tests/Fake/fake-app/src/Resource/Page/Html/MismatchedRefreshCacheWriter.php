<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\RefreshCache;
use BEAR\Resource\ResourceObject;

/** A #[DonutCache] page whose writes carry #[RefreshCache] and #[Purge] and do not need onGet's required parameter */
#[DonutCache]
class MismatchedRefreshCacheWriter extends ResourceObject
{
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id];

        return $this;
    }

    #[RefreshCache]
    #[Purge(uri: 'app://self/refresh-dest?id=1')]
    public function onPost(string $title): static
    {
        $this->body = ['title' => $title];

        return $this;
    }

    #[RefreshCache]
    #[Purge(uri: 'app://self/refresh-dest?id=1')]
    public function onPut(string $title): static
    {
        $this->body = ['title' => $title];

        return $this;
    }
}
