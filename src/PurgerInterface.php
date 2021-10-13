<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

interface PurgerInterface
{
    public function __invoke(string $tag): void;
}
