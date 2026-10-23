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
 * ServerContextInterface.
 */
final class UncacheableMark implements UncacheableMarkInterface
{
    private string|null $reason = null;

    #[Override]
    public function mark(string $reason): void
    {
        $this->reason ??= $reason;
    }

    #[Override]
    public function isMarked(): bool
    {
        return $this->reason !== null;
    }
}
