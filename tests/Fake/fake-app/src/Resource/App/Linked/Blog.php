<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Linked;

use BEAR\Resource\ResourceObject;

/** Counts how many times the link to it is followed */
class Blog extends ResourceObject
{
    public static int $runs = 0;

    public function onGet(int $id): static
    {
        self::$runs++;
        $this->body = ['blog' => $id];

        return $this;
    }
}
