<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\Resource\ResourceObject;
use RuntimeException;

/**
 * A hole that throws an ANONYMOUS exception class - a design that identified a swallowed
 * render by matching the exception class name in a reconstructed warning message would
 * silently miss this (Kimi K3 review, MEDIUM: `RuntimeException@anonymous\0...` does not
 * match a class-name regex) and still store the empty template. The current design never
 * inspects the class at all: it lets `$ro->toString()` propagate the Throwable unexamined.
 */
class AnonymousThrowingHole extends ResourceObject
{
    public function onGet(): ResourceObject
    {
        throw new class ('anonymous hole blew up') extends RuntimeException {
        };
    }
}
