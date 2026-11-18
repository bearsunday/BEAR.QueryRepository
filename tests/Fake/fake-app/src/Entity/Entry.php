<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Entity;

/** A row object: its public properties are its values */
final class Entry
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
    ) {
    }
}
