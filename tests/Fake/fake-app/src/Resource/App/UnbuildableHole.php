<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\Resource\ResourceObject;
use RuntimeException;

/** A hole whose construction fails `$failureCount` more times, reset per test */
class UnbuildableHole extends ResourceObject
{
    public static int $failureCount = 0;

    public function __construct()
    {
        if (self::$failureCount > 0) {
            self::$failureCount--;

            throw new RuntimeException('the hole cannot be built');
        }
    }

    public function onGet(): static
    {
        $this->body['value'] = 'healthy';

        return $this;
    }
}
