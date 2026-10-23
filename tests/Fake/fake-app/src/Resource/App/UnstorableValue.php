<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\UncacheableMarkInterface;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * Stands in for a #[Cacheable] resource that issues a per-user value (a CSRF token) - issue #222
 */
#[Cacheable(expirySecond: 60)]
class UnstorableValue extends ResourceObject
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

        $this->body = ['token' => 'secret'];

        return $this;
    }
}
