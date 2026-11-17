<?php

namespace FakeVendor\HelloWorld\Resource\Page\Mx;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\RefreshCache;
use BEAR\Resource\ResourceObject;

/** Weaving matrix fixture: a #[DonutCache] write that #[RefreshCache] refreshes and #[Purge] names another URI for */
#[DonutCache]
class DCRefreshPurge extends ResourceObject
{
    /** Number of times a write body really ran */
    public static int $ran = 0;

    /** Number of times onGet() ran */
    public static int $gets = 0;

    public function onGet(int $id = 0): static
    {
        self::$gets++;
        $this->body = ['v' => $id];

        return $this;
    }

    #[RefreshCache]
    #[Purge(uri: 'page://self/dep/level-two')]
    public function onPut(int $id = 0): static
    {
        self::$ran++;
        $this->code = 204;

        return $this;
    }

    #[RefreshCache]
    #[Purge(uri: 'page://self/dep/level-two')]
    public function onPost(int $id = 0): static
    {
        self::$ran++;
        $this->code = 204;

        return $this;
    }

    #[RefreshCache]
    #[Purge(uri: 'page://self/dep/level-two')]
    public function onDelete(int $id = 0): static
    {
        self::$ran++;
        $this->code = 204;

        return $this;
    }

    #[RefreshCache]
    #[Purge(uri: 'page://self/dep/level-two')]
    public function onPatch(int $id = 0): static
    {
        self::$ran++;
        $this->code = 204;

        return $this;
    }
}
