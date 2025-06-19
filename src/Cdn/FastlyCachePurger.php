<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Cdn;

use BEAR\FastlyModule\FastlyCachePurgerInterface;
use BEAR\QueryRepository\PurgerInterface;
use Override;

final class FastlyCachePurger implements PurgerInterface
{
    public function __construct(private readonly FastlyCachePurgerInterface $fastlyCachePurger)
    {
    }

    #[Override]
    public function __invoke(string $tag): void
    {
        ($this->fastlyCachePurger)($tag);
    }
}
