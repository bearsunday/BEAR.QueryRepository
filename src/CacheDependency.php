<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

use function assert;
use function sprintf;

class CacheDependency implements CacheDependencyInterface
{
    public const CACHE_DEPENDENCY = 'cache_deps';

    public function depends(ResourceObject $from, ResourceObject $to): void
    {
        assert(! isset($from->headers[self::CACHE_DEPENDENCY]));

        $cacheDepedencyTags = $to->headers['ETag'];
        if (isset($to->headers[self::CACHE_DEPENDENCY])) {
            $cacheDepedencyTags .= sprintf(' %s', $to->headers[self::CACHE_DEPENDENCY]);
            unset($to->headers[self::CACHE_DEPENDENCY]);
        }

        $from->headers[self::CACHE_DEPENDENCY] = $cacheDepedencyTags;
    }
}
