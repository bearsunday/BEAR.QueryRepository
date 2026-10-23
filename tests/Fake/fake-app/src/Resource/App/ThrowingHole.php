<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\UncacheableMarkInterface;
use BEAR\Resource\ResourceObject;
use RuntimeException;

/**
 * Stands in for a hole that marks itself and then fails mid-render (issue #222 stack-balance
 * test): its own mark must be attributed to its own scope, and that scope must be left even
 * though the hole never returns normally, or a later mark would be misattributed to it.
 */
class ThrowingHole extends ResourceObject
{
    public function __construct(
        private readonly UncacheableMarkInterface $mark,
    ) {
    }

    public function onGet() : ResourceObject
    {
        $this->mark->mark('csrf-token-issued');

        throw new RuntimeException('the hole blew up mid-render');
    }
}
