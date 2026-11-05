<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/** Requested with camelCase (`?userId=1`) or snake_case (`?user_id=1`) keys; BEAR.Resource binds both to `$userId`. */
#[Cacheable]
class SnakeQueryParam extends ResourceObject
{
    public function onGet(int $userId, int $page = 1): static
    {
        $this->body = ['userId' => $userId, 'page' => $page, 'time' => \microtime(true)];

        return $this;
    }
}
