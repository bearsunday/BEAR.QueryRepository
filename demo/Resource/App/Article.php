<?php

declare(strict_types=1);

namespace FakeVendor\DemoApp\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Article extends ResourceObject
{
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id, 'title' => "Article {$id}"];

        return $this;
    }
}
