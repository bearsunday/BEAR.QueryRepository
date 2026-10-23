<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\UncacheableMarkInterface;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceObject;

/**
 * Sets CDN-facing headers directly in onGet(), bypassing CdnCacheControlHeaderSetterInterface,
 * and marks itself unstorable: those headers must not survive the refusal (issue #222).
 */
#[CacheableResponse]
class UnstorableWithManualCdnHeader extends ResourceObject
{
    public function __construct(
        private readonly UncacheableMarkInterface $mark,
    ) {
    }

    public function onGet() : ResourceObject
    {
        $this->mark->mark('csrf-token-issued');
        $this->headers['CDN-Cache-Control'] = 'max-age=600';
        $this->headers['Surrogate-Control'] = 'max-age=600';
        $this->body['article'] = '1';

        return $this;
    }
}
