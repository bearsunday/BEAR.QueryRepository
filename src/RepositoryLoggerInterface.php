<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

interface RepositoryLoggerInterface
{
    /** @param mixed ...$values */
    public function log(string $template, ...$values): void;

    public function __toString(): string;
}
