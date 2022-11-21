<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

final class NullCacheControlHeaderSetter implements CdnCacheControlHeaderSetterInterface
{
    public function __invoke(ResourceObject $ro, int|null $sMaxAge): void
    {
    }
}
