<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\LogJson;
use Override;

use function chmod;
use function dirname;
use function error_log;
use function fclose;
use function flock;
use function fopen;
use function fwrite;
use function is_dir;
use function json_encode;
use function mkdir;
use function str_contains;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const LOCK_EX;
use const LOCK_UN;
use const PHP_EOL;

/**
 * One line of JSON per session, appended to a stream or a file
 *
 * The shape a log collector expects: `php://stdout` in a container, a path when the host ships
 * files. Writes take an exclusive lock: a session line is longer than an atomic pipe write, so
 * concurrent workers would interleave halves of it. A wrapper that refuses the lock is written
 * anyway rather than dropped.
 *
 * A file target is created 0600 in a 0700 directory; a stream target inherits whatever the host
 * points it at.
 */
final class LogStreamWriter implements LogWriterInterface
{
    public function __construct(private string $stream = 'php://stdout')
    {
    }

    #[Override]
    public function write(LogJson $log): void
    {
        if ($log->records === []) {
            return; // nothing was recorded
        }

        $json = json_encode($log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || ! $this->prepareDir()) {
            return;
        }

        $handle = @fopen($this->stream, 'ab');
        if ($handle === false) {
            error_log('QueryRepository log: cannot open ' . $this->stream);

            return;
        }

        @flock($handle, LOCK_EX);
        fwrite($handle, $json . PHP_EOL);
        @flock($handle, LOCK_UN);
        fclose($handle);
        $this->restrict();
    }

    private function isFile(): bool
    {
        return ! str_contains($this->stream, '://');
    }

    private function prepareDir(): bool
    {
        if (! $this->isFile()) {
            return true;
        }

        $dir = dirname($this->stream);
        if (is_dir($dir) || @mkdir($dir, 0700, true) || is_dir($dir)) {
            return true;
        }

        error_log('QueryRepository log: cannot create the log directory ' . $dir);

        return false;
    }

    private function restrict(): void
    {
        if (! $this->isFile()) {
            return;
        }

        @chmod($this->stream, 0600);
    }
}
