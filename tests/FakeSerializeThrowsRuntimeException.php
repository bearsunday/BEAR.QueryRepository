<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use RuntimeException;

/** A value whose own __serialize() fails with a subclass, not the exact built-in Exception */
final class FakeSerializeThrowsRuntimeException
{
    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new RuntimeException('a real failure, not a serialization refusal');
    }
}
