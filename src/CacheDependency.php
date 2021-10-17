<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

use function assert;
use function sprintf;

final class CacheDependency implements CacheDependencyInterface
{
    /** @var CacheKey */
    private $cacheKey;

    public function __construct(CacheKey $cacheKey)
    {
        $this->cacheKey = $cacheKey;
    }

    public function depends(ResourceObject $from, ResourceObject $to): void
    {
        assert(! isset($from->headers[Header::SURROGATE_KEY]));

        $cacheDepedencyTags = ($this->cacheKey)($to->uri);
        if (isset($to->headers[Header::SURROGATE_KEY])) {
            $cacheDepedencyTags .= sprintf(' %s', $to->headers[Header::SURROGATE_KEY]);
            unset($to->headers[Header::SURROGATE_KEY]);
        }

        $from->headers[Header::SURROGATE_KEY] = $cacheDepedencyTags;
    }
}
