<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\LogJson;
use Override;

use function array_slice;
use function bin2hex;
use function chmod;
use function count;
use function error_log;
use function file_put_contents;
use function glob;
use function gmdate;
use function is_dir;
use function json_encode;
use function microtime;
use function mkdir;
use function random_bytes;
use function rename;
use function rsort;
use function sprintf;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * One file per session, plus a fixed path for the latest one
 *
 * `stree` takes a single log file, so a session is written whole rather than appended as a
 * line: `vendor/bin/stree var/log/query-repository/latest.json` renders the request that
 * just ran, and the timestamped siblings keep the scenario before it. Names are UTC so they
 * sort chronologically, and `latest.json` is renamed into place so a reader never opens a
 * half-written file. Under concurrency `latest.json` holds whichever session finished last,
 * which is not necessarily the newest timestamped sibling.
 *
 * A destination that cannot be written is reported to `error_log()` and otherwise ignored: the
 * log is a side channel, and a full disk or a read-only mount must not change how the request
 * ended. Sessions carry request URIs with their query strings, client validators and exception
 * text, so the directory is created 0700 and the files 0600 - treat them as application logs.
 */
final class LogFileWriter implements LogWriterInterface
{
    public const LATEST = 'latest.json';
    private const SESSION_GLOB = '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9]-*.json';

    /** @param int $keep number of timestamped files to retain (the oldest are removed) */
    public function __construct(
        private string $dir,
        private int $keep = 100,
    ) {
    }

    #[Override]
    public function write(LogJson $log): void
    {
        if ($log->open === [] && $log->events === []) {
            return; // nothing was recorded: an empty file would claim a request had no cache activity
        }

        $json = json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || ! $this->prepareDir()) {
            return;
        }

        $now = microtime(true);
        $seconds = (int) $now;
        $micro = (int) (($now - (float) $seconds) * 1000000.0);
        $name = sprintf('%s-%06d.json', gmdate('Ymd-His', $seconds), $micro);
        if (! $this->put($this->dir . '/' . $name, $json)) {
            return;
        }

        $this->putLatest($json);
        $this->prune();
    }

    private function prepareDir(): bool
    {
        // The second is_dir() is the concurrent case: a sibling process won the mkdir
        if (is_dir($this->dir) || @mkdir($this->dir, 0700, true) || is_dir($this->dir)) {
            return true;
        }

        error_log('QueryRepository log: cannot create the log directory ' . $this->dir);

        return false;
    }

    private function put(string $path, string $json): bool
    {
        if (@file_put_contents($path, $json) === false) {
            error_log('QueryRepository log: cannot write ' . $path);

            return false;
        }

        @chmod($path, 0600);

        return true;
    }

    /** Renamed into place so a concurrent reader sees either the old session or the new one */
    private function putLatest(string $json): void
    {
        $latest = $this->dir . '/' . self::LATEST;
        $pending = $latest . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if ($this->put($pending, $json) && @rename($pending, $latest)) {
            return;
        }

        @unlink($pending); // a copy that could not be written or moved is not left behind
    }

    private function prune(): void
    {
        $files = glob($this->dir . '/' . self::SESSION_GLOB);
        if ($files === false || count($files) <= $this->keep) {
            return;
        }

        rsort($files);
        foreach (array_slice($files, $this->keep) as $stale) {
            @unlink($stale); // a sibling process pruning the same list is not an error
        }
    }
}
