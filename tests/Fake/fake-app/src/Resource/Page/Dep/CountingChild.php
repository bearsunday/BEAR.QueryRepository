<?php

namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\Resource\ResourceObject;

/**
 * Non-Cacheable embedded child that counts its real executions
 *
 * No #[Cacheable]: nothing absorbs a second invocation, so $count is the number of
 * times the request was really run. The body changes on every call, so two
 * materializations of one parent store are distinguishable.
 */
class CountingChild extends ResourceObject
{
    /** @var int */
    public static $count = 0;

    public function onGet()
    {
        $this->body = ['count' => ++self::$count];

        return $this;
    }
}
