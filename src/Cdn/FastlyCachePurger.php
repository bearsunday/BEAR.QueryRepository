<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Cdn;

use BEAR\FastlyModule\FastlyCachePurgerInterface;
use BEAR\QueryRepository\PurgerInterface;

final class FastlyCachePurger implements PurgerInterface
{
    public function __construct(private FastlyCachePurgerInterface $fastlyCachePurger)
    {
    }

    public function __invoke(string $tag): void
    {
        ($this->fastlyCachePurger)($tag);
    }
}
