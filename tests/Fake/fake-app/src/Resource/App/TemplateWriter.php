<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class TemplateWriter extends ResourceObject
{
    public function onGet(string $id): static
    {
        $this->body = ['id' => $id];

        return $this;
    }

    #[Purge(uri: 'app://self/refresh-dest?id={category}')]
    public function onPut(string $id, string $category): static
    {
        unset($id, $category);

        return $this;
    }
}
