<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnstorableResponseDetected;
use BEAR\Resource\ResourceObject;
use Override;

/**
 * {@inheritDoc}
 *
 * Dev-only decorator (bound by DevUnstorableGateModule) that turns the package's default
 * skip-and-log into a thrown exception, so a developer sees a response that would silently
 * never be cached instead of discovering it later in the semantic log.
 */
final readonly class ThrowingUnstorableGate implements UnstorableGateInterface
{
    public function __construct(
        private UnstorableGate $gate,
    ) {
    }

    /** @return 'unstorable'|null */
    #[Override]
    public function reason(ResourceObject $ro, string|null $declaredCacheControl = null): string|null
    {
        $reason = $this->gate->reason($ro, $declaredCacheControl);
        if ($reason !== null) {
            throw new UnstorableResponseDetected((string) $ro->uri, $reason);
        }

        return null;
    }
}
