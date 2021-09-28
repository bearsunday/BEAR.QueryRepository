<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

interface CacheDependencyInterface
{
    public function depends(ResourceObject $from, ResourceObject $to): void;
}
