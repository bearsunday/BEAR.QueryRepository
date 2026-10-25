<?php

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\Resource\ResourceObject;

/**
 * A non-idempotent resource: every onGet() run bumps a process-wide counter
 *
 * Plain (no #[Cacheable]/#[DonutCache]) - the dice: every explicit `$resource->get()` and
 * every embedded read runs this, the same as any other call. Used to prove that a donut
 * page's single-execution reuse of an already-read embed (issue #226) applies only through
 * the one execution it was already read from, never to a fresh, direct call.
 */
class Counter extends ResourceObject
{
    public static int $calls = 0;

    public function onGet(string|null $tag = null): static
    {
        self::$calls++;
        $this->body = [
            'call' => self::$calls,
            'tag' => $tag,
        ];

        return $this;
    }
}
