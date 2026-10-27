<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld;

use RuntimeException;
use Stringable;

/**
 * Fails stringification exactly `$failCount` more times, then succeeds - the template-render
 * equivalent of `FlakyHole`'s onGet() failure, used to make a hole's own template (not its
 * onGet()) fail on `ResourceDonut::refresh()`'s re-fetch.
 */
final class ThrowingOnStringifyValue implements Stringable
{
    public static int $failCount = 0;

    public function __toString(): string
    {
        if (self::$failCount > 0) {
            self::$failCount--;

            throw new RuntimeException('the hole template is currently broken');
        }

        return 'healthy';
    }
}
