<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

interface PurgerInterface
{
    /** @param string $tag One or more tags separated by a space */
    public function __invoke(string $tag): void;
}
