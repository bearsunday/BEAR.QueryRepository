<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\KeepMutationsAndFailures;
use BEAR\QueryRepository\Log\LogSinkInterface;
use BEAR\QueryRepository\Log\LogStreamWriter;
use BEAR\QueryRepository\Log\LogWriterInterface;
use BEAR\QueryRepository\Log\PolicyLogWriter;
use BEAR\QueryRepository\Log\SafeSemanticLoggerProvider;
use BEAR\QueryRepository\Log\ShutdownFlush;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

/**
 * Records every session, writes the ones that can explain an incident
 *
 * A healthy read is measurably empty of information, so the session is buffered and judged at
 * flush: mutations, failures and a sample survive as one JSON line each. What this buys is
 * forensics, not monitoring - hit rates and capacity belong to metrics, which are cheaper and
 * more accurate at it. What metrics cannot answer is which tags a purge reached, because a
 * cache write that never happened leaves nothing behind but this log.
 *
 * Requires a request-per-process host (PHP-FPM, CLI). On Swoole or RoadRunner the shutdown
 * flush is the wrong boundary and warns instead of recording; see issue #179.
 *
 * An app that wants to force full recording for one request implements
 * RetentionPolicyInterface and overrides the binding.
 */
final class ProdQueryRepositoryLogModule extends AbstractModule
{
    /**
     * @param string $stream     `php://stdout` for a collector, or a file path
     * @param int    $sampleRate keep 1 healthy session in N as a baseline; 0 disables sampling
     */
    public function __construct(
        private string $stream = 'php://stdout',
        private int $sampleRate = 0,
        AbstractModule|null $module = null,
    ) {
        parent::__construct($module);
    }

    #[Override]
    protected function configure(): void
    {
        $this->bind(LogWriterInterface::class)->toInstance(
            new PolicyLogWriter(new KeepMutationsAndFailures($this->sampleRate), new LogStreamWriter($this->stream)),
        );
        $this->bind(LogSinkInterface::class)->to(ShutdownFlush::class)->in(Scope::SINGLETON);
        $this->bind(SemanticLoggerInterface::class)->toProvider(SafeSemanticLoggerProvider::class)->in(Scope::SINGLETON);
    }
}
