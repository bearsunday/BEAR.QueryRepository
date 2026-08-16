<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\TopLevelAwareInterface;
use Koriym\SemanticLogger\AbstractContext;
use Koriym\SemanticLogger\LogJson;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;

use function count;

/**
 * Test double: a TopLevelAware logger that is NOT SafeSemanticLogger
 *
 * Proves manual-scope rooting is granted by the TopLevelAwareInterface
 * capability, not by the concrete SafeSemanticLogger class. Records every
 * context like RecordingSemanticLogger and tracks open/close depth for
 * isTopLevel().
 */
final class RecordingTopLevelAwareLogger implements SemanticLoggerInterface, TopLevelAwareInterface
{
    private const SEMANTIC_LOG_SCHEMA_URL = 'https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json';

    /** @var list<AbstractContext> */
    public array $opens = [];

    /** @var list<AbstractContext> */
    public array $events = [];

    /** @var list<AbstractContext> */
    public array $closes = [];
    private int $depth = 0;

    #[Override]
    public function isTopLevel(): bool
    {
        return $this->depth === 0;
    }

    #[Override]
    public function open(AbstractContext $context): string
    {
        $this->opens[] = $context;
        $this->depth++;

        return (string) count($this->opens);
    }

    #[Override]
    public function event(AbstractContext $context): void
    {
        $this->events[] = $context;
    }

    #[Override]
    public function close(AbstractContext $context, string $openId): void
    {
        $this->closes[] = $context;
        $this->depth--;
    }

    /** {@inheritDoc} */
    #[Override]
    public function flush(array $links = []): LogJson
    {
        $this->depth = 0; // a flush ends the session, as SafeSemanticLogger's does

        return new LogJson(self::SEMANTIC_LOG_SCHEMA_URL, [], [], [], $links);
    }
}
