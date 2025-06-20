<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Override;

final class NullCacheControlHeaderSetter implements CdnCacheControlHeaderSetterInterface
{
    #[Override]
    public function __invoke(ResourceObject $ro, int|null $sMaxAge): void
    {
    }
}
