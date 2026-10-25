<?php

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\Resource\ResourceObject;

/**
 * A non-idempotent resource: every onGet() run bumps a process-wide counter
 *
 * Plain (no #[Cacheable]/#[DonutCache]) - the dice: every explicit `$resource->get()` and
 * every embedded read runs this, the same as any other call. Used to prove that
 * `OnceRequest`'s once-only semantics apply only when `EmbedOnceInterceptor` actually wraps
 * an embed, never to a direct call.
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
