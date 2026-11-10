<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

/** Names itself, a resource with no onGet() */
class GetlessTargetWriter extends ResourceObject
{
    public static int $writes = 0;

    #[Purge(uri: 'app://self/getless-target-writer')]
    public function onPost(): static
    {
        self::$writes++;

        return $this;
    }
}
