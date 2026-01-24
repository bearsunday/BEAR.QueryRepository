<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;
use Stringable;

use function array_map;
use function implode;
use function json_encode;

use const JSON_UNESCAPED_SLASHES;
use const PHP_EOL;

final class RepositoryLogger implements RepositoryLoggerInterface, Stringable
{
    /** @var list<array<string, mixed>> */
    private array $logs = [];

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function log(string $operation, array $context = []): void
    {
        $this->logs[] = ['op' => $operation, ...$context];
    }

    #[Override]
    public function __toString(): string
    {
        return implode(PHP_EOL, array_map(
            static fn (array $log): string => (string) json_encode($log, JSON_UNESCAPED_SLASHES),
            $this->logs,
        ));
    }
}
