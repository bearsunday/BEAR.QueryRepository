<?php

declare(strict_types=1);

namespace FakeVendor\DemoApp;

use Stringable;

/** A value object passed as a query value, such as a ULID */
final class TagId implements Stringable
{
    public function __construct(private readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
