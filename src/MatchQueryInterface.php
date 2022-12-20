<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

interface MatchQueryInterface
{
    /** @return array<string, mixed> */
    public function __invoke(ResourceObject $ro): array;
}
