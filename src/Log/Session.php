<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;

/** One request's log: its open-scope depth and the logger recording it */
final class Session
{
    public int $depth = 0;

    public function __construct(
        public readonly SemanticLoggerInterface $logger = new SemanticLogger(),
    ) {
    }
}
