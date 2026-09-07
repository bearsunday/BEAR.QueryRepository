<?php

namespace FakeVendor\HelloWorld\Resource\Page\Mx;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\ResourceObject;

/** Weaving matrix fixture: class donut, method entire-content - two declarations reaching one onGet */
#[DonutCache]
class DCMR extends ResourceObject
{
    /** Number of times a write body really ran */
    public static int $ran = 0;

    #[CacheableResponse]
    public function onGet(int $id = 0): static
    {
        $this->body = ['v' => $id];

        return $this;
    }

    public function onPut(int $id = 0): static
    {
        self::$ran++;
        $this->code = 204;

        return $this;
    }

    public function onPost(int $id = 0): static
    {
        self::$ran++;
        $this->code = 204;

        return $this;
    }

    public function onDelete(int $id = 0): static
    {
        self::$ran++;
        $this->code = 204;

        return $this;
    }
}
