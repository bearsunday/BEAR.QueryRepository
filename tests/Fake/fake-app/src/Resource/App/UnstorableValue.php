<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * Stands in for a #[Cacheable] resource that issues a per-user value (a CSRF token) by
 * setting its own Cache-Control to private at runtime
 */
#[Cacheable(expirySecond: 60)]
class UnstorableValue extends ResourceObject
{
    public function onGet(bool $unstorable = true): ResourceObject
    {
        if ($unstorable) {
            $this->headers[Header::CACHE_CONTROL] = 'private';
        }

        $this->body = ['token' => 'secret'];

        return $this;
    }
}
