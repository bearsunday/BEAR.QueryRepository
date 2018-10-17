<?php

declare(strict_types=1);

namespace FakeVendor\DemoApp\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable(expirySecond=300)
 */
class User extends ResourceObject
{
    public static $i = 0;
    private $data = [];

    public function __construct()
    {
        $this->data[1]['name'] = 'bear';
    }

    public function onGet($id)
    {
        \error_log('*** onGet() method invoked ***');
        $this->body = [
            'name' => isset($this->data[$id]) ? $this->data[$id]['name'] : '',
            'update' => self::$i++
        ];

        return $this;
    }

    public function onPatch($id, $name)
    {
        \error_log('*** onPatch() method invoked ***');
        $this->data[$id]['name'] = $name;

        return $this;
    }
}
