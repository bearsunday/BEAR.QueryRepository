<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

/**
 * @deprecated Since the SemanticLogger migration. Cache logging now uses
 *   {@see \Koriym\SemanticLogger\SemanticLoggerInterface} with typed Context objects and a
 *   nested open/event/close tree. This flat interface is retained only for backward
 *   compatibility and no longer receives internal cache events.
 */
interface RepositoryLoggerInterface
{
    /** @param array<string, mixed> $context */
    public function log(string $operation, array $context = []): void;

    /**
     * Reset the logger state
     *
     * This method clears all accumulated logs. It should be called at the end of
     * each request in long-running environments (Swoole, RoadRunner) to prevent
     * log accumulation across requests.
     */
    public function reset(): void;

    public function __toString(): string;
}
