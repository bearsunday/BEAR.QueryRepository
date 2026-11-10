<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\FakeRefreshFailure;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class FlakyTagPage extends ResourceObject
{
    public static int $version = 1;
    public static string|null $failOn = null;

    public function onGet(string $id): static
    {
        if ($id === self::$failOn) {
            throw new FakeRefreshFailure($id);
        }

        $this->body = ['v' => $id . ':v' . self::$version];

        return $this;
    }
}
