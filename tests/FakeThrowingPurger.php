<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;

/**
 * A purger that always throws, to test that invalidateTags() is fail-closed: it logs
 * the outcome as cdn=failed and then propagates the purge failure.
 */
final class FakeThrowingPurger implements PurgerInterface
{
    #[Override]
    public function __invoke(string $tag): void
    {
        throw new FakeCdnPurgeFailed('purge failed: ' . $tag);
    }
}
