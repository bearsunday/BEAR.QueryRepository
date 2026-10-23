<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\UncacheableMarkInterface;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceObject;

/**
 * Stands in for a #[CacheableResponse] page that issues a per-user value (a CSRF token) - issue #222
 */
#[CacheableResponse]
class UnstorableDonut extends ResourceObject
{
    public function __construct(
        private readonly UncacheableMarkInterface $mark,
    ) {
    }

    public function onGet(bool $unstorable = true) : ResourceObject
    {
        if ($unstorable) {
            $this->mark->mark('csrf-token-issued');
        }

        $this->body['token'] = 'secret';

        return $this;
    }
}
