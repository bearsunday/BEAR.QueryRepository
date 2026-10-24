<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\Header;
use BEAR\QueryRepository\UncacheableMarkInterface;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * A #[Cacheable] resource that sets its own Cache-Control to `private` directly in onGet(),
 * bypassing #[HttpCache] entirely - the owner-approved regression case (issue #222): this
 * still stops the server-side store, but - unlike a producer that calls mark() - keeps the
 * developer's own header instead of being overwritten with `private, no-store`.
 */
#[Cacheable(expirySecond: 60)]
class HandSetPrivateCacheControl extends ResourceObject
{
    public function __construct(
        private readonly UncacheableMarkInterface $mark,
    ) {
    }

    public function onGet(bool $alsoMark = false): static
    {
        if ($alsoMark) {
            $this->mark->mark('csrf-token-issued');
        }

        $this->headers[Header::CACHE_CONTROL] = 'private';
        $this->body = ['token' => 'value'];

        return $this;
    }
}
