<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Assisted;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\ResourceParam;
use BEAR\Resource\ResourceObject;

/** A method-level #[CacheableResponse] reader */
class Mr extends ResourceObject
{
    #[CacheableResponse]
    public function onGet(string $id, #[ResourceParam(uri: 'app://self/user?id=1#name')] string $owner): static
    {
        $this->body = ['v' => $id . ':' . $owner];

        return $this;
    }
}
