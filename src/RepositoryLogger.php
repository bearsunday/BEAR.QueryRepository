<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Stringable;

use function implode;
use function is_array;
use function sprintf;

use const PHP_EOL;

final class RepositoryLogger implements RepositoryLoggerInterface, Stringable
{
    /** @var list<string> */
    private array $logs = [];

    /**
     * {@inheritDoc}
     */
    public function log(string $template, ...$values): void
    {
        /** @var bool|float|int|string|list<string>|null $value */
        foreach ($values as &$value) {
            if (is_array($value)) {
                $value = $value !== [] ? implode(' ', $value) : '';
            }
        }

        unset($value);
        /** @var list<string> $values */
        $msg = sprintf($template, ...$values);

        $this->logs[] = $msg;
    }

    public function __toString(): string
    {
        return implode(PHP_EOL, $this->logs);
    }
}
