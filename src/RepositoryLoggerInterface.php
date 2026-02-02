<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

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
