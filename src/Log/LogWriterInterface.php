<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\LogJson;

/**
 * Destination of a flushed log session
 *
 * Implementations must be serializable: the compiled app carries the writer across the
 * injector's serialization boundary, so a writer holds a path or a stream name, never an
 * open handle. Open at write time.
 */
interface LogWriterInterface
{
    public function write(LogJson $log): void;
}
