<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/** Written with camelCase (`userId`) or snake_case (`user_id`) keys; BEAR.Resource binds both to `$userId`. */
#[Cacheable]
class UserPosts extends ResourceObject
{
    public function onGet(int $userId): static
    {
        $this->body = ['userId' => $userId, 'time' => \microtime(true)];

        return $this;
    }

    public function onPut(int $userId): static
    {
        unset($userId);

        return $this;
    }

    public function onPost(int $userId): static
    {
        unset($userId);

        return $this;
    }
}
