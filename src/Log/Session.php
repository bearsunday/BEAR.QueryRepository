<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;

/**
 * What a SessionStoreInterface hands back: one request's open-scope depth and its logger
 *
 * Depth used to live on SafeSemanticLogger itself; it moves here because it is exactly as
 * per-request as the logger it counts scopes for, and the two must travel together.
 */
final class Session
{
    public int $depth = 0;

    public function __construct(
        public readonly SemanticLoggerInterface $logger = new SemanticLogger(),
    ) {
    }
}
