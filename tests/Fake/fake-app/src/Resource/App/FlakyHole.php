<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\Resource\ResourceObject;
use RuntimeException;

/**
 * A hole whose failure is controlled by a call counter, reset per test: fails exactly
 * `$failureCount` more times, then succeeds. A transient failure decrements to 0 and
 * recovers on the very next call (ResourceDonut::refresh()'s own re-fetch, then - if that
 * also fails - AbstractDonutCacheInterceptor's fresh `proceed()` fallback); a persistent one
 * is simulated by setting a count high enough that both attempts still fail.
 */
class FlakyHole extends ResourceObject
{
    public static int $failureCount = 0;

    public function onGet(): static
    {
        if (self::$failureCount > 0) {
            self::$failureCount--;

            throw new RuntimeException('the hole is currently broken');
        }

        $this->body['value'] = 'healthy';

        return $this;
    }
}
