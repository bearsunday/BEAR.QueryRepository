<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

interface CdnCacheControlHeaderSetterInterface
{
    public function __invoke(ResourceObject $ro, int|null $sMaxAge): void;
}
