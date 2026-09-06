<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;

final class FakeCountingPurger implements PurgerInterface
{
    /** @var list<string> */
    public array $tags = [];

    #[Override]
    public function __invoke(string $tag): void
    {
        $this->tags[] = $tag;
    }
}
