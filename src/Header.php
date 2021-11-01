<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

final class Header
{
    /**
     * Purge Keys
     *
     * Tags for cache invalidation.
     */
    public const SURROGATE_KEY = 'Surrogate-Key';
    public const ETAG = 'ETag';
    public const CDN_CACHE_CONTROL = 'CDN-Cache-Control';
    public const CACHE_CONTROL = 'Cache-Control';
    public const AGE = 'Age';
    public const LAST_MODIFIED = 'Last-Modified';
    public const HTTP_IF_NONE_MATCH = 'HTTP_IF_NONE_MATCH';
}
