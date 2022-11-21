<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

use function sprintf;

/**
 * @see https://www.ietf.org/archive/id/draft-cdn-control-header-01.html
 * @see https://blog.cloudflare.com/cdn-cache-control/
 */
final class CdnCacheControlHeaderSetter implements CdnCacheControlHeaderSetterInterface
{
    private const CDN_CACHE_CONTROL_HEADER = Header::CDN_CACHE_CONTROL;

    public function __invoke(ResourceObject $ro, int|null $sMaxAge): void
    {
        $sMaxAge ??= 10;
        if (! isset($ro->headers[self::CDN_CACHE_CONTROL_HEADER])) {
            $ro->headers[self::CDN_CACHE_CONTROL_HEADER] = sprintf('max-age=%s stale-while-revalidate=10', (string) $sMaxAge);
        }
    }
}
