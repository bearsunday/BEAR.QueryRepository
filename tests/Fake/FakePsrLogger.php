<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Psr\Log\AbstractLogger;

/** Records what a host's PSR-3 logger was handed */
final class FakePsrLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     *
     * @inheritDoc
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}
