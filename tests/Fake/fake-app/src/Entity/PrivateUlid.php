<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Entity;

use BEAR\RepositoryModule\Annotation\DependsOn;

#[DependsOn(uri: 'app://self/rows/article?ulid={ulid}')]
final class PrivateUlid
{
    public function __construct(
        private string $ulid,
    ) {
    }

    public function ulid(): string
    {
        return $this->ulid;
    }
}
