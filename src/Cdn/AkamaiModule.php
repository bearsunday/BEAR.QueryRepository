<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Cdn;

use BEAR\QueryRepository\CdnCacheControlHeaderSetterInterface;
use Ray\Di\AbstractModule;

/**
 * @see https://developer.akamai.com/blog/2019/03/28/technical-deep-dive-purging-cache-tag for Purging by Cache Tag
 * @see https://www.akamai.com/blog/news/targeted-cache-control for Akamai-Cache-Control
 * @see https://techdocs.akamai.com/purge-cache/reference/api for purge API
 */
final class AkamaiModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->bind(CdnCacheControlHeaderSetterInterface::class)->to(AkamaiCacheControlHeaderSetter::class);
    }
}
