<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Entity;

use Override;
use Stringable;

final class Ulid implements Stringable
{
    public static int $casts = 0;

    public function __construct(
        private readonly string $value,
    ) {
    }

    #[Override]
    public function __toString(): string
    {
        self::$casts++;

        return $this->value;
    }
}
