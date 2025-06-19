<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;

final class NullPurger implements PurgerInterface
{
    #[Override]
    public function __invoke(string $tag): void
    {
    }
}
