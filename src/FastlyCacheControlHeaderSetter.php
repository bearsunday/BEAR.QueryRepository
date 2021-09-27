<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

use function sprintf;

final class FastlyCacheControlHeaderSetter implements CdnCacheControlHeaderSetterInterface
{
    public const CDN_CACHE_CONTROL_HEADER = 'Surrogate-Control';

    public function __invoke(ResourceObject $ro, ?int $sMaxAge): void
    {
        $sMaxAge = $sMaxAge ?? 31536000;
        $ro->headers[self::CDN_CACHE_CONTROL_HEADER] = sprintf('max-age=%s', (string) $sMaxAge);
    }
}
