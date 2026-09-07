<?php

namespace FakeVendor\HelloWorld\Resource\Page\Mx;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\RefreshCache;
use BEAR\Resource\ResourceObject;

/** Weaving matrix fixture: DCPurge */
class DCPurge extends ResourceObject
{
    /** Number of times a write body really ran */
    public static int $ran = 0;

    #[DonutCache]
    public function onGet(int $id = 0): static
    {
        $this->body = ['v' => $id];

        return $this;
    }

    #[Purge(uri: 'page://self/mx/DCPurge?id=1')]
    public function onPut(int $id = 0): static
    {
        self::$ran++;
        $this->code = 204;

        return $this;
    }

    #[Purge(uri: 'page://self/mx/DCPurge?id=1')]
    public function onPost(int $id = 0): static
    {
        self::$ran++;
        $this->code = 204;

        return $this;
    }

    #[Purge(uri: 'page://self/mx/DCPurge?id=1')]
    public function onDelete(int $id = 0): static
    {
        self::$ran++;
        $this->code = 204;

        return $this;
    }

}
