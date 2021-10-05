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
        assert(! isset($from->headers[Header::PURGE_KEYS]));

        $cacheDepedencyTags = $to->headers[Header::ETAG];
        if (isset($to->headers[Header::PURGE_KEYS])) {
            $cacheDepedencyTags .= sprintf(' %s', $to->headers[Header::PURGE_KEYS]);
            unset($to->headers[Header::PURGE_KEYS]);
        }

        $from->headers[Header::PURGE_KEYS] = $cacheDepedencyTags;
    }
}
