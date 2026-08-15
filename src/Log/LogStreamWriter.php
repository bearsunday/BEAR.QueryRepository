<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use BEAR\QueryRepository\Exception\UnsupportedLogStream;
use Koriym\SemanticLogger\LogJson;
use Override;

use function chmod;
use function dirname;
use function error_log;
use function fclose;
use function flock;
use function fopen;
use function fwrite;
use function implode;
use function in_array;
use function is_dir;
use function json_encode;
use function mkdir;
use function sprintf;
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
 * files. Writes take an exclusive lock so concurrent workers cannot interleave half-lines - a
 * kept session runs to thousands of bytes, well past the size a pipe write is atomic for. A
 * wrapper that refuses locking is written anyway rather than dropped.
 *
 * The target is restricted to the two shapes documented above. Anything else - `php://filter/…`,
 * `ftp://`, `ssh2.sftp://` - would let a module argument truncate an unrelated file or send every
 * session over the network, so it is rejected rather than honoured.
 *
 * Sessions carry request URIs with their query strings, client validators and exception text. A
 * file target is created 0600 and its directory 0700; a stream target inherits whatever the host
 * points it at.
 */
final class LogStreamWriter implements LogWriterInterface
{
    private const STREAMS = ['php://stdout', 'php://stderr', 'php://output'];

    public function __construct(private string $stream = 'php://stdout')
    {
        if (in_array($stream, self::STREAMS, true) || ! str_contains($stream, '://')) {
            return;
        }

        throw new UnsupportedLogStream(sprintf(
            '%s: expected a filesystem path or one of %s',
            $stream,
            implode(', ', self::STREAMS),
        ));
    }

    #[Override]
    public function write(LogJson $log): void
    {
        if ($log->open === [] && $log->events === []) {
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
        return ! in_array($this->stream, self::STREAMS, true);
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
