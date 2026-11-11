<?php

declare(strict_types=1);

namespace FakeVendor\DemoApp\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\ResourceObject;

class TagWriter extends ResourceObject
{
    /** @param list<string> $id */
    #[Purge(uri: 'app://self/tag{?id*}')]
    public function onDelete(array $id): static
    {
        return $this->write($id);
    }

    /** @param list<string> $id */
    #[Refresh(uri: 'app://self/tag{?id*}')]
    public function onPut(array $id): static
    {
        return $this->write($id);
    }

    /** @param list<string> $id */
    private function write(array $id): static
    {
        Tag::$version++;
        $this->body = ['id' => $id];

        return $this;
    }
}
