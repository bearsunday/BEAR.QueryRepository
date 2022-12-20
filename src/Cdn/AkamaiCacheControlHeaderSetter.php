<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Cdn;

use BEAR\QueryRepository\CdnCacheControlHeaderSetterInterface;
use BEAR\QueryRepository\Header;
use BEAR\Resource\ResourceObject;

use function sprintf;

final class AkamaiCacheControlHeaderSetter implements CdnCacheControlHeaderSetterInterface
{
    public const CDN_CACHE_CONTROL_HEADER = 'Akamai-Cache-Control';
    private const PURGE_KEYS = 'Edge-Cache-Tag';

    public function __invoke(ResourceObject $ro, int|null $sMaxAge): void
    {
        $sMaxAge ??= 31_536_000;
        if (isset($ro->headers[Header::SURROGATE_KEY])) {
            $ro->headers[self::PURGE_KEYS] = $ro->headers[Header::SURROGATE_KEY];
            unset($ro->headers[Header::SURROGATE_KEY]);
        }

        if (! isset($ro->headers[self::CDN_CACHE_CONTROL_HEADER])) {
            $ro->headers[self::CDN_CACHE_CONTROL_HEADER] = sprintf('max-age=%s', (string) $sMaxAge);
        }
    }
}
