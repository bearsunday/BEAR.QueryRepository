<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\Resource\ResourceObject;

/**
 * A hole whose `onGet()` succeeds - no template file exists for it, so its OWN render
 * (not its `onGet()`) is what fails. Exercises `DonutRequest::__toString()`'s
 * `$executed->toString()` call specifically: `ThrowingHole`'s failure happens earlier,
 * inside `($this->request)()`, and never reaches that line at all.
 */
class HoleWithNoTemplate extends ResourceObject
{
    public function onGet(): static
    {
        $this->body['value'] = 'never rendered';

        return $this;
    }
}
