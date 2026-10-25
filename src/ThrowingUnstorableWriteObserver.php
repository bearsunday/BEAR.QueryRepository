<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnstorableResponseDetected;
use BEAR\Resource\ResourceObject;
use Override;

/**
 * {@inheritDoc}
 *
 * Bound by `DevUnstorableGateModule` (Scope::SINGLETON - pending state must be shared across
 * every injection point in one request/container, the same reason `UncacheableMark` is).
 *
 * Replaces the old `ThrowingUnstorableGate`, which threw from inside a hole's own render:
 * `ResourceObject::__toString()` degrades that to an `E_USER_WARNING` and an empty string, so
 * the developer never actually saw the exception, only a silently empty template. skipped()
 * suppresses a hole legitimately refusing its OWN write
 * (`UncacheableMarkInterface::isMarkedForCurrentScope()` true while `isNested()` is also true
 * - e.g. a CSRF form hole) - expected, not worth reporting - and otherwise records only the
 * FIRST skip of the request (sticky, mirroring `UncacheableMark::mark()`'s own
 * sticky-once-set behaviour); later skips in the same request do not overwrite it.
 * raiseIfPending() throws for that recorded skip, then clears it via clearPending(), so a
 * later, unrelated request sharing this same (SINGLETON) instance starts clean. clearPending()
 * is also called directly - without raising - by the interceptor's own finally when a genuine
 * exception is already propagating: raising there would replace that exception, which the
 * interceptor must never do (see UnstorableWriteObserverInterface's own docblock).
 */
final class ThrowingUnstorableWriteObserver implements UnstorableWriteObserverInterface
{
    private ResourceObject|null $pendingRo = null;
    private string|null $pendingReason = null;

    public function __construct(
        private readonly UncacheableMarkInterface $mark,
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
