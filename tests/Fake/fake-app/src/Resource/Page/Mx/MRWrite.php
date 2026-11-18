<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Mx;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

/** A class with no cache attribute whose read and write each carry #[CacheableResponse] */
class MRWrite extends ResourceObject
{
    #[CacheableResponse]
    public function onGet(int $id = 0): static
    {
        $this->body = ['v' => $id];

        return $this;
    }

    #[CacheableResponse]
    public function onPut(int $id = 0): static
    {
        $this->code = 204;

        return $this;
    }

    #[CacheableResponse]
    #[Purge(uri: 'page://self/dep/level-one')]
    public function onPost(int $id = 0): static
    {
        $this->code = 204;

        return $this;
    }
}
