<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Entity;

use BEAR\RepositoryModule\Annotation\DependsOn;

#[DependsOn(uri: 'app://self/rows/article?ulid={ulid}')]
final class ArrayUlid
{
    /** @param list<string> $ulid */
    public function __construct(
        public array $ulid,
    ) {
    }
}
