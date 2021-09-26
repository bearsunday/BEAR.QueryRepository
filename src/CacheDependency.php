<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

use function assert;
use function sprintf;

class CacheDependency implements CacheDependencyInterface
{
    public const SURROGATE_KEY = 'Surrogate-Key';

    public function depends(ResourceObject $from, ResourceObject $to): void
    {
        assert(! isset($from->headers[self::SURROGATE_KEY]));

        $cacheDepedencyTags = $to->headers['ETag'];
        if (isset($to->headers[self::SURROGATE_KEY])) {
            $cacheDepedencyTags .= sprintf(' %s', $to->headers[self::SURROGATE_KEY]);
            unset($to->headers[self::SURROGATE_KEY]);
        }

        $from->headers[self::SURROGATE_KEY] = $cacheDepedencyTags;
    }
}
