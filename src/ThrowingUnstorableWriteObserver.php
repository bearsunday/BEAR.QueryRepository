<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnstorableResponseDetected;
use BEAR\Resource\ResourceObject;
use Override;

/**
 * {@inheritDoc}
 *
 * Bound by `DevUnstorableGateModule` (`Scope::SINGLETON` - pending state must be shared
 * across every injection point in one request/container). `skipped()` suppresses a hole
 * legitimately refusing its own write and otherwise records only the first skip of the
 * request (sticky); `raiseIfPending()` throws for it and clears, so a later request sharing
 * this instance starts clean. `clearPending()` is also called directly, without raising,
 * when a genuine exception is already propagating - raising there would replace it.
 */
final class ThrowingUnstorableWriteObserver implements UnstorableWriteObserverInterface
{
    private ResourceObject|null $pendingRo = null;
    private string|null $pendingReason = null;

    public function __construct(
        private readonly UncacheableMarkScopeInterface $mark,
    ) {
    }

    #[Override]
    public function skipped(ResourceObject $ro, string $reason): void
    {
        if ($this->pendingRo !== null || ($this->mark->isMarkedForCurrentScope() && $this->mark->isNested())) {
            return;
        }

        $this->pendingRo = $ro;
        $this->pendingReason = $reason;
    }

    #[Override]
    public function raiseIfPending(): void
    {
        if ($this->pendingRo === null || $this->pendingReason === null) {
            return;
        }

        $ro = $this->pendingRo;
        $reason = $this->pendingReason;
        $this->clearPending();

        throw new UnstorableResponseDetected((string) $ro->uri, $reason);
    }

    #[Override]
    public function clearPending(): void
    {
        $this->pendingRo = null;
        $this->pendingReason = null;
    }
}
