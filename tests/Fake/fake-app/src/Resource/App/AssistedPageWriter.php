<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

class AssistedPageWriter extends ResourceObject
{
    public static int $writes = 0;

    #[Purge(uri: 'app://self/assisted-page{?id}')]
    public function onPost(string $id): static
    {
        self::$writes++;
        $this->body = ['id' => $id];

        return $this;
    }
}
