<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;

/**
 * No-op repository logger
 *
 * Null-object default for logger-aware classes so that direct instantiation
 * (without DI) keeps working without emitting any log. Mirrors NullPurger.
 *
 * @deprecated Since the SemanticLogger migration; use {@see \BEAR\QueryRepository\Log\NullSemanticLogger}.
 * @psalm-suppress DeprecatedInterface  Deprecated null-object intentionally implements the deprecated interface.
 */
final class NullRepositoryLogger implements RepositoryLoggerInterface
{
    #[Override]
    public function log(string $operation, array $context = []): void
    {
    }

    #[Override]
    public function reset(): void
    {
    }

    #[Override]
    public function __toString(): string
    {
        return '';
    }
}
