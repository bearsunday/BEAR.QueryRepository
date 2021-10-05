<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

final class Header
{
    public const ETAG = 'ETag';
    public const PURGE_KEYS = 'Surrogate-Key';
    public const CDN_CACHE_CONTROL = 'CDN-Cache-Control';
    public const CACHE_CONTROL = 'Cache-Control';
    public const AGE = 'Age';
    public const LAST_MODIFIED = 'Last-Modified';
    public const HTTP_IF_NONE_MATCH = 'HTTP_IF_NONE_MATCH';
}
