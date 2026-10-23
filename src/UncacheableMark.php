<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;

use function array_key_last;
use function array_pop;

/**
 * {@inheritDoc}
 *
 * Bound Scope::SINGLETON (see QueryRepositoryModule): one instance per DI container, which is
 * one per request in the usual BEAR.Sunday bootstrap - the same lifetime ResourceStorage and
 * QueryRepository already rely on. A long-running worker (Swoole, RoadRunner) that reuses one
 * container across requests must rebind this per request, the same way it already must for
 * ServerContextInterface; left unrebound, a mark from one request would silently disable the
 * store for every request after it.
 */
final class UncacheableMark implements UncacheableMarkInterface
{
    /** The top-level page's own scope, distinct from any real URI */
    private const TOP_LEVEL = '';

    /** @var list<string> */
    private array $stack = [];

    /** @var array<string, true> */
    private array $markedOwners = [];

    #[Override]
    public function mark(string $reason): void
    {
        $this->markedOwners[$this->currentOwner()] = true;
    }

    #[Override]
    public function isMarked(): bool
    {
        return $this->markedOwners !== [];
    }

    #[Override]
    public function isMarkedForCurrentScope(): bool
    {
        return isset($this->markedOwners[$this->currentOwner()]);
    }

    #[Override]
    public function enterHole(string $uri): void
    {
        $this->stack[] = $uri;
    }

    #[Override]
    public function leaveHole(): void
    {
        if ($this->stack === []) {
            return;
        }

        array_pop($this->stack);
    }

    private function currentOwner(): string
    {
        return $this->stack === [] ? self::TOP_LEVEL : $this->stack[array_key_last($this->stack)];
    }
}
