<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceObject;

/** UserPosts on the donut path (DonutCommandInterceptor) */
#[CacheableResponse]
class UserPostsResponse extends ResourceObject
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
