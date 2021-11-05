<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use function serialize;
use function unserialize;

trait Php73BcSerializableTrait
{
    /**
     * {@inheritDoc}
     */
    final public function serialize()
    {
        return serialize($this->__serialize());
    }

    /**
     * @psalm-suppress all
     *
     * {@inheritDoc}
     */
    final public function unserialize($serializedData)
    {
        $array = unserialize($serializedData);
        $this->__unserialize($array); // @phpstan-ignore-line
    }
}
