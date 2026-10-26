<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

/**
 * Observes a write the package skipped because the response was unstorable - see
 * CHANGELOG/README for the design rationale.
 *
 * The package default (NullUnstorableWriteObserver) does nothing; `DevUnstorableGateModule`
 * overrides it with `WarningUnstorableWriteObserver`, which reports a skip as an
 * `E_USER_WARNING` - never for a hole legitimately refusing its own write.
 */
interface UnstorableWriteObserverInterface
{
    /** Called only at a site that actually skips a store, never at a header-only check */
    public function skipped(ResourceObject $ro, string $reason): void;
}
