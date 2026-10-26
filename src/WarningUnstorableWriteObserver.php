<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Override;

use function sprintf;
use function trigger_error;

use const E_USER_WARNING;

/**
 * {@inheritDoc}
 *
 * Bound by `DevUnstorableGateModule`. Stays silent for a hole legitimately refusing its own
 * write (`isMarkedForCurrentScope()` true while `isNested()` is also true - e.g. a CSRF
 * form hole), since that is expected, not worth a warning.
 */
final readonly class WarningUnstorableWriteObserver implements UnstorableWriteObserverInterface
{
    public function __construct(
        private UncacheableMarkScopeInterface $mark,
    ) {
    }

    #[Override]
    public function skipped(ResourceObject $ro, string $reason): void
    {
        if ($this->mark->isMarkedForCurrentScope() && $this->mark->isNested()) {
            return;
        }

        trigger_error(sprintf('put_skipped %s: %s', $reason, (string) $ro->uri), E_USER_WARNING);
    }
}
