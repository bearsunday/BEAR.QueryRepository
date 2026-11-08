<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use LogicException;

/** A value whose own __serialize() throws the LogicException a request refuses with, from another class */
final class FakeSerializeThrowsLogicException
{
    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('a real failure, not a request refusing to serialize');
    }
}
