<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Cdn;

use BEAR\QueryRepository\CdnCacheControlHeaderSetterInterface;
use BEAR\Resource\ResourceObject;

use function sprintf;

final class FastlyCacheControlHeaderSetter implements CdnCacheControlHeaderSetterInterface
{
    public const CDN_CACHE_CONTROL_HEADER = 'Surrogate-Control';

    public function __invoke(ResourceObject $ro, int|null $sMaxAge): void
    {
        $sMaxAge ??= 31_536_000;
        if (! isset($ro->headers[self::CDN_CACHE_CONTROL_HEADER])) {
            $ro->headers[self::CDN_CACHE_CONTROL_HEADER] = sprintf('max-age=%s', (string) $sMaxAge);
        }
    }
}
