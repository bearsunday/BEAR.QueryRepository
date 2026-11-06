<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Exception;

/** A value whose own __serialize() fails for a reason that is not a serialization refusal */
final class FakeSerializeThrowsException
{
    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new Exception('__serialize failure, not a serialization refusal');
    }
}
