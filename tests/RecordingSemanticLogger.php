<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Koriym\SemanticLogger\AbstractContext;
use Koriym\SemanticLogger\LogJson;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;

use function count;
use function is_string;

/**
 * Test double that records every context passed to open/event/close
 *
 * Lets unit tests assert on the typed Context objects an object emits, without
 * the open/close LIFO and flush-needs-a-completed-operation constraints of the
 * real SemanticLogger.
 */
final class RecordingSemanticLogger implements SemanticLoggerInterface
{
    private const SEMANTIC_LOG_SCHEMA_URL = 'https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json';

    /** @var list<AbstractContext> */
    public array $opens = [];

    /** @var list<AbstractContext> */
    public array $events = [];

    /** @var list<AbstractContext> */
    public array $closes = [];

    #[Override]
    public function open(AbstractContext $context): string
    {
        $this->opens[] = $context;

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
    }

    /** {@inheritDoc} */
    #[Override]
    public function flush(array $links = []): LogJson
    {
        return new LogJson(self::SEMANTIC_LOG_SCHEMA_URL, [], [], [], $links);
    }

    /**
     * Types of every recorded entry (opens, events, closes) for sequence assertions
     *
     * @return list<string>
     */
    public function types(): array
    {
        $types = [];
        foreach ([...$this->opens, ...$this->events, ...$this->closes] as $context) {
            $type = $context::TYPE;
            $types[] = is_string($type) ? $type : '';
        }

        return $types;
    }
}
