<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;
use Stringable;

use function array_map;
use function implode;
use function is_string;
use function json_encode;

use const JSON_UNESCAPED_SLASHES;
use const PHP_EOL;

final class RepositoryLogger implements StructuredRepositoryLoggerInterface, Stringable
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

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function reset(): void
    {
        $this->logs = [];
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getLogs(): array
    {
        return $this->logs;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getOps(): array
    {
        return array_map(
            /** @param array<string, mixed> $log */
            static function (array $log): string {
                /** @var mixed $op */
                $op = $log['op'] ?? '';

                return is_string($op) ? $op : '';
            },
            $this->logs,
        );
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
