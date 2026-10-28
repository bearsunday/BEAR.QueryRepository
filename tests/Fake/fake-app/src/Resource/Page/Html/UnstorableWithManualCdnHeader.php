<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceObject;

/**
 * Sets CDN-facing headers directly in onGet(), bypassing CdnCacheControlHeaderSetterInterface,
 * and sets its own Cache-Control to private: those headers must not survive the refusal.
 */
#[CacheableResponse]
class UnstorableWithManualCdnHeader extends ResourceObject
{
    public function onGet(): ResourceObject
    {
        $this->headers[Header::CACHE_CONTROL] = 'private';
        $this->headers['CDN-Cache-Control'] = 'max-age=600';
        $this->headers['Surrogate-Control'] = 'max-age=600';
        $this->body['article'] = '1';

        return $this;
    }
}
