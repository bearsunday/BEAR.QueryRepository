<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld;

use BEAR\QueryRepository\UncacheableMarkInterface;
use Stringable;

/**
 * Stands in for a Twig helper (e.g. a CSRF token function) that marks the response unstorable
 * as a side effect of being stringified during template rendering (issue #222).
 */
final class RenderTimeToken implements Stringable
{
    public function __construct(
        private readonly UncacheableMarkInterface $mark,
    ) {
    }

    public function __toString(): string
    {
        $this->mark->mark('csrf-token-issued');

        return 'secret-token';
    }
}
