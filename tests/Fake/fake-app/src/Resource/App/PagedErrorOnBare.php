<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/** A #[Cacheable] value resource whose bare (page 1) GET answers 503 while $fail is set. */
#[Cacheable]
class PagedErrorOnBare extends ResourceObject
{
    public static bool $fail = false;

    public function onGet(int $page = 1)
    {
        $this->code = self::$fail && $page === 1 ? 503 : 200;
        $this->body = ['page' => $page, 'time' => \microtime(true)];

        return $this;
    }
}
