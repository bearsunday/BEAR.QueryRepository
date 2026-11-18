<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Entity;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * A list object whose rows are read only by iterating it
 *
 * @implements IteratorAggregate<int, Entry>
 */
final class EntryList implements IteratorAggregate
{
    /** @param list<Entry> $items */
    public function __construct(
        private readonly array $items,
    ) {
    }

    /** @return Traversable<int, Entry> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
