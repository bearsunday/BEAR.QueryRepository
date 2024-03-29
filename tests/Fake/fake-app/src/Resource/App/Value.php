<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable(type="value")
 */
#[Cacheable(type: "value")]
class Value extends ResourceObject
{
    public static $i = 1;

    public function toString(): string
    {
        if ($this->view) {
            return $this->view;
        }
        $this->view = (string) self::$i++ . $this['time'];

        return $this->view;
    }

    public function onGet()
    {
        $this['time'] = (string) \microtime(true);

        return $this;
    }
}
