<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\ResourceParam;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class AssistedPage extends ResourceObject
{
    public function onGet(string $id, #[ResourceParam(uri: 'app://self/user?id=1#name', param: 'owner')] string $owner): static
    {
        $this->body = ['v' => $id . ':' . $owner];

        return $this;
    }
}
