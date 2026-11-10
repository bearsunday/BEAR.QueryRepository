<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Stringable;

final class FakeStringableId implements Stringable
{
    public function __construct(private readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
