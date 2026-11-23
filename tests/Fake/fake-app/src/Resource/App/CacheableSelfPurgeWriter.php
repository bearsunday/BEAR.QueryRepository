<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class CacheableSelfPurgeWriter extends ResourceObject
{
    public static int $gets = 0;

    public function onGet(string $id): static
    {
        self::$gets++;
        $this->body = ['id' => $id];

        return $this;
    }

    #[Purge(uri: 'app://self/cacheable-self-purge-writer{?id}')]
    public function onPut(string $id): static
    {
        unset($id);

        return $this;
    }
}
