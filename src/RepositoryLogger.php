<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use function error_log;
use function implode;
use function sprintf;

use const PHP_EOL;

final class RepositoryLogger implements RepositoryLoggerInterface
{
    /** @var list<string> */
    private $logs = [];

    /**
     * {@inheritDoc}
     */
    public function log(string $template, ...$values): void
    {
        /** @psalm-suppress MixedArgument */
        $msg = sprintf($template, ...$values);

        $this->logs[] = $msg;
        /** @noinspection ForgottenDebugOutputInspection */
        error_log($msg);
    }

    public function __toString(): string
    {
        return implode(PHP_EOL, $this->logs);
    }
}
