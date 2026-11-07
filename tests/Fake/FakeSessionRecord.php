<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Koriym\SemanticLogger\LogJson;

/** What each fake log writer received, in the order the writes happened */
final class FakeSessionRecord
{
    /** @var list<array{class-string, LogJson}> */
    public static array $received = [];
}
