<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

final class NullPurger implements PurgerInterface
{
    public function __invoke(string $tag): void
    {
    }
}
