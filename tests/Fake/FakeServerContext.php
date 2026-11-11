<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;

/** A server context read from an array instead of $_SERVER */
final class FakeServerContext implements ServerContextInterface
{
    /** @param array<string, string> $server */
    public function __construct(private readonly array $server)
    {
    }

    #[Override]
    public function get(string $key): string|null
    {
        return $this->server[$key] ?? null;
    }

    #[Override]
    public function has(string $key): bool
    {
        return isset($this->server[$key]);
    }
}
