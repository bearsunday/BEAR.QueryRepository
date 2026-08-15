<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\LogFileWriter;
use BEAR\QueryRepository\Log\LogSinkInterface;
use BEAR\QueryRepository\Log\LogWriterInterface;
use BEAR\QueryRepository\Log\SafeSemanticLoggerProvider;
use BEAR\QueryRepository\Log\ShutdownFlush;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

/**
 * Records every session and writes it where a reader can open it
 *
 * Install in development. Each request leaves one file, so the tree that explains what the
 * cache just did is a single command away:
 *
 *   vendor/bin/stree var/log/query-repository/latest.json
 *
 * Nothing else changes: the log is a side channel, and cache behaviour is identical with it
 * on or off.
 */
final class DevQueryRepositoryLogModule extends AbstractModule
{
    /** @param int $keep timestamped sessions to retain beside latest.json */
    public function __construct(
        private string $logDir,
        private int $keep = 100,
        AbstractModule|null $module = null,
    ) {
        parent::__construct($module);
    }

    #[Override]
    protected function configure(): void
    {
        $this->bind(LogWriterInterface::class)->toInstance(new LogFileWriter($this->logDir, $this->keep));
        $this->bind(LogSinkInterface::class)->to(ShutdownFlush::class)->in(Scope::SINGLETON);
        // Shared session: open() at an interceptor and event() at storage resolve to one logger
        $this->bind(SemanticLoggerInterface::class)->toProvider(SafeSemanticLoggerProvider::class)->in(Scope::SINGLETON);
    }
}
