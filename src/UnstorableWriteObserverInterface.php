<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

/**
 * Observes a write the package skipped because the response was unstorable - see
 * CHANGELOG/README for the design rationale.
 *
 * The package default (NullUnstorableWriteObserver) does nothing; `DevUnstorableGateModule`
 * overrides it with `ThrowingUnstorableWriteObserver`, which surfaces a skip as a thrown
 * exception once, after rendering completes - never mid-render, and never for a hole
 * legitimately refusing its own write.
 */
interface UnstorableWriteObserverInterface
{
    /** Called only at a site that actually skips a store, never at a header-only check */
    public function skipped(ResourceObject $ro, string $reason): void;

    /**
     * Called once per top-level request, after the OUTERMOST cacheable GET interceptor
     * returns NORMALLY - never while a template is still mid-render.
     */
    public function raiseIfPending(): void;

    /** Drop a pending skip without raising - see raiseIfPending()'s own docblock */
    public function clearPending(): void;
}
