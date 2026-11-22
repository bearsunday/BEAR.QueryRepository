<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** An uncached resource that returns the next article each time it runs */
class Turn extends ResourceObject
{
    public static int $turn = 0;

    #[DependsOn(uri: 'app://self/rows/article?ulid={ulid}')]
    public function onGet(): static
    {
        $this->body['ulid'] = 'a' . ++self::$turn;

        return $this;
    }
}
