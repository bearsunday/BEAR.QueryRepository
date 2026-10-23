<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;

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
    private string|null $reason = null;
    private string|null $outsideHoleReason = null;
    private int $holeDepth = 0;

    #[Override]
    public function mark(string $reason): void
    {
        $this->reason ??= $reason;
        if ($this->holeDepth === 0) {
            $this->outsideHoleReason ??= $reason;
        }
    }

    #[Override]
    public function isMarked(): bool
    {
        return $this->reason !== null;
    }

    #[Override]
    public function isMarkedOutsideHole(): bool
    {
        return $this->outsideHoleReason !== null;
    }

    #[Override]
    public function enterHole(): void
    {
        $this->holeDepth++;
    }

    #[Override]
    public function leaveHole(): void
    {
        $this->holeDepth--;
    }
}
