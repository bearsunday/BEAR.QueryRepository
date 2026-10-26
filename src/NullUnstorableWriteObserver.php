<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Override;

/**
 * {@inheritDoc}
 *
 * The package default (bound by QueryRepositoryModule): a skipped write is already recorded as
 * a `put_skipped` semantic-log event, and this package never throws for application-level
 * conditions by default (see this project's error-handling policy).
 */
final class NullUnstorableWriteObserver implements UnstorableWriteObserverInterface
{
    #[Override]
    public function skipped(ResourceObject $ro, string $reason): void
    {
    }
}
