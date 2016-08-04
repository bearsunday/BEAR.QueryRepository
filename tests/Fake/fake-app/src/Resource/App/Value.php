<?php

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable(type="value")
 */
class Value extends ResourceObject
{
    static $i = 1;

    public function __toString()
    {
        if ($this->view) {
            return $this->view;
        }
        $this->view = (string) self::$i++ . $this['time'];

        return $this->view;
    }

    public function onGet()
    {
        $this['time'] = (string) microtime(true);

        return $this;
    }
}
