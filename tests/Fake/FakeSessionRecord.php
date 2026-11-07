<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Koriym\SemanticLogger\LogJson;

final class FakeSessionRecord
{
    /** @var list<array{class-string, LogJson}> */
    public static array $received = [];
}
