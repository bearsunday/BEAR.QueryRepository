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

    /**
     * RFC 7231 date format for HTTP headers
     *
     * @see https://www.rfc-editor.org/rfc/rfc7231#section-7.1.1.1
     */
    public const RFC7231 = 'D, d M Y H:i:s \G\M\T';
    public const HTTP_IF_NONE_MATCH = 'HTTP_IF_NONE_MATCH';
}
