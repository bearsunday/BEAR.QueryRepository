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
    public function reason(ResourceObject $ro): string|null
    {
        return $this->throwIfUnstorable($this->gate->reason($ro), $ro);
    }

    /** @return 'unstorable'|null */
    #[Override]
    public function reasonExcludingHoleMarks(ResourceObject $ro): string|null
    {
        return $this->throwIfUnstorable($this->gate->reasonExcludingHoleMarks($ro), $ro);
    }

    /**
     * @param 'unstorable'|null $reason
     *
     * @return null a non-null $reason always throws
     */
    private function throwIfUnstorable(string|null $reason, ResourceObject $ro): null
    {
        if ($reason !== null) {
            throw new UnstorableResponseDetected((string) $ro->uri, $reason);
        }

        return null;
    }
}
