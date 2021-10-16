<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

use function assert;
use function sprintf;

class CacheDependency implements CacheDependencyInterface
{
    public function depends(ResourceObject $from, ResourceObject $to): void
    {
        assert(! isset($from->headers[Header::SURROGATE_KEY]));

        $cacheDepedencyTags = $to->headers[Header::ETAG];
        if (isset($to->headers[Header::SURROGATE_KEY])) {
            $cacheDepedencyTags .= sprintf(' %s', $to->headers[Header::SURROGATE_KEY]);
            unset($to->headers[Header::SURROGATE_KEY]);
        }

        $from->headers[Header::SURROGATE_KEY] = $cacheDepedencyTags;
    }
}
