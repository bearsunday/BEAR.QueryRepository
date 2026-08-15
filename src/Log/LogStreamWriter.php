<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\LogJson;
use Override;

use function file_put_contents;
use function json_encode;
use function str_starts_with;

use const FILE_APPEND;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const LOCK_EX;
use const PHP_EOL;

/**
 * One line of JSON per session, appended to a stream or a file
 *
 * The shape a log collector expects: `php://stdout` in a container, a path when the host
 * ships files. A locked append keeps concurrent workers from interleaving half-lines;
 * PHP streams do not support locking, so the flag is dropped for those.
 */
final class LogStreamWriter implements LogWriterInterface
{
    public function __construct(private string $stream = 'php://stdout')
    {
    }

    #[Override]
    public function write(LogJson $log): void
    {
        if ($log->open === [] && $log->events === []) {
            return; // nothing was recorded
        }

        $line = (string) json_encode($log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        $flags = str_starts_with($this->stream, 'php://') ? 0 : FILE_APPEND | LOCK_EX;
        file_put_contents($this->stream, $line, $flags);
    }
}
