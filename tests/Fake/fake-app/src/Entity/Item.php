<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Entity;

use Stringable;

final class Item
{
    public string $title = 'title';

    public function __construct(
        public string|Stringable $ulid,
        public string|null $seriesId,
    ) {
    }
}
