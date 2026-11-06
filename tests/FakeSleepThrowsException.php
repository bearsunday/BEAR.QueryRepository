<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Exception;

/** A value whose own __sleep() fails for a reason that is not a serialization refusal */
final class FakeSleepThrowsException
{
    /** @return list<string> */
    public function __sleep(): array
    {
        throw new Exception('__sleep failure, not a serialization refusal');
    }
}
