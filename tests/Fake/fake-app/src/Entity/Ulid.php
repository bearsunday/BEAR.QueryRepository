<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Entity;

use Override;
use Stringable;

final class Ulid implements Stringable
{
    public function __construct(
        private readonly string $value,
    ) {
    }

    #[Override]
    public function __toString(): string
    {
        return $this->value;
    }
}
