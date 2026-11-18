<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Assisted;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\ResourceParam;
use BEAR\Resource\ResourceObject;

/** A #[Cacheable] writer whose onGet() reads one argument through #[ResourceParam] */
#[Cacheable]
class CacheableWriter extends ResourceObject
{
    /** Number of times onGet() ran */
    public static int $gets = 0;

    public function onGet(string $id, #[ResourceParam(uri: 'app://self/user?id=1#name')] string $owner): static
    {
        self::$gets++;
        $this->body = ['v' => $id . ':' . $owner];

        return $this;
    }

    public function onPut(string $id): static
    {
        $this->code = 204;

        return $this;
    }
}
