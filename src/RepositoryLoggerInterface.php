<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

interface RepositoryLoggerInterface
{
    /** @param array<string, mixed> $context */
    public function log(string $operation, array $context = []): void;

    public function __toString(): string;
}
