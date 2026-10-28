<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceObject;

/**
 * Stands in for a #[CacheableResponse] page that issues a per-user value (a CSRF token) by
 * setting its own Cache-Control to private at runtime
 */
#[CacheableResponse]
class UnstorableDonut extends ResourceObject
{
    public function onGet(bool $unstorable = true): ResourceObject
    {
        if ($unstorable) {
            $this->headers[Header::CACHE_CONTROL] = 'private';
        }

        $this->body['token'] = 'secret';

        return $this;
    }
}
