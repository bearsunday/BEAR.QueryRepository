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
use function stripos;
use function strlen;
use function substr;

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
 * A file target, a path or a `file://` URL, is created 0600 in a 0700 directory; a stream target
 * inherits whatever the host points it at.
 */
final class LogStreamWriter implements LogWriterInterface
{
    private const FILE_SCHEME = 'file://';
    private const LOCALHOST = 'localhost';

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

    /** @return string|null the path of a file target; null for a stream */
    private function path(): string|null
    {
        if (stripos($this->stream, self::FILE_SCHEME) === 0) {
            $path = substr($this->stream, strlen(self::FILE_SCHEME));

            // fopen() opens file://localhost/x as /x
            return stripos($path, self::LOCALHOST . '/') === 0 ? substr($path, strlen(self::LOCALHOST)) : $path;
        }

        return str_contains($this->stream, '://') ? null : $this->stream;
    }

    private function prepareDir(): bool
    {
        $path = $this->path();
        if ($path === null) {
            return true;
        }

        $dir = dirname($path);
        if (is_dir($dir) || @mkdir($dir, 0700, true) || is_dir($dir)) {
            return true;
        }

        error_log('QueryRepository log: cannot create the log directory ' . $dir);

        return false;
    }

    private function restrict(): void
    {
        $path = $this->path();
        if ($path === null) {
            return;
        }

        @chmod($path, 0600);
    }
}
