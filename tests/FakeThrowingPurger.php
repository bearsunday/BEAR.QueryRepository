<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;
use RuntimeException;

/**
 * A purger that always throws, to test that invalidate-etag records purgerOk=false
 * (and still logs the outcome) when the CDN purge fails.
 */
final class FakeThrowingPurger implements PurgerInterface
{
    #[Override]
    public function __invoke(string $tag): void
    {
        throw new RuntimeException('purge failed: ' . $tag);
    }
}
