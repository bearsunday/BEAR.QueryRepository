<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\LogJson;
use Override;

use function array_slice;
use function count;
use function date;
use function file_put_contents;
use function glob;
use function hrtime;
use function is_dir;
use function json_encode;
use function mkdir;
use function rsort;
use function sprintf;
use function substr;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const LOCK_EX;

/**
 * One file per session, plus a fixed path for the latest one
 *
 * `stree` takes a single log file, so a session is written whole rather than appended as a
 * line: `vendor/bin/stree var/log/query-repository/latest.json` renders the request that
 * just ran, and the timestamped siblings keep the scenario before it.
 */
final class LogFileWriter implements LogWriterInterface
{
    public const LATEST = 'latest.json';

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

        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }

        $json = (string) json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $name = sprintf('%s-%s.json', date('Ymd-His'), substr((string) hrtime(true), -6));
        file_put_contents($this->dir . '/' . $name, $json, LOCK_EX);
        file_put_contents($this->dir . '/' . self::LATEST, $json, LOCK_EX);
        $this->prune();
    }

    private function prune(): void
    {
        $files = glob($this->dir . '/20*.json');
        if ($files === false || count($files) <= $this->keep) {
            return;
        }

        rsort($files);
        foreach (array_slice($files, $this->keep) as $stale) {
            unlink($stale);
        }
    }
}
