<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\LogFileWriter;
use BEAR\QueryRepository\Log\LogSinkInterface;
use BEAR\QueryRepository\Log\LogWriterInterface;
use BEAR\QueryRepository\Log\ProcessSession;
use BEAR\QueryRepository\Log\SafeSemanticLoggerProvider;
use BEAR\QueryRepository\Log\SessionStoreInterface;
use BEAR\QueryRepository\Log\ShutdownFlush;
use BEAR\RepositoryModule\Annotation\CacheLog;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Di\AbstractModule;
use Ray\Di\MultiBinder;
use Ray\Di\Scope;

/**
 * Records every session and writes it where a reader can open it
 *
 * Install in development. Each process leaves one file when it shuts down: one request under PHP-FPM, while a
 * CLI script that makes several requests writes them all into that one file. The tree that explains what the
 * cache just did is a single command away:
 *
 *   vendor/bin/stree var/log/query-repository/latest.json
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
        // ShutdownFlush injects this set, which must exist even when an app adds no writer
        MultiBinder::newInstance($this, LogWriterInterface::class);
        $this->bind(SessionStoreInterface::class)->to(ProcessSession::class)->in(Scope::SINGLETON);
        // Shared session: open() at an interceptor and event() at storage resolve to one logger
        $this->bind(SemanticLoggerInterface::class)->annotatedWith(CacheLog::class)->toProvider(SafeSemanticLoggerProvider::class)->in(Scope::SINGLETON);
    }
}
