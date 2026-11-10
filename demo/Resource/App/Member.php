<?php

declare(strict_types=1);

namespace FakeVendor\DemoApp\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Member extends ResourceObject
{
    /** @var array<int, string> */
    private static array $names = [1 => 'bear'];

    public function onGet(int $memberId): static
    {
        $this->body = ['memberId' => $memberId, 'name' => self::$names[$memberId] ?? ''];

        return $this;
    }

    public function onPatch(int $memberId, string $name): static
    {
        self::$names[$memberId] = $name;

        return $this;
    }
}
