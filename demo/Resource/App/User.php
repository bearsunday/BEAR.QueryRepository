<?php

declare(strict_types=1);

namespace FakeVendor\DemoApp\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

use function error_log;

#[Cacheable]
class User extends ResourceObject
{
    public static $i = 0;

    /** Static, so the new instance the refresh after a write creates sees the write */
    private static $data = [1 => ['name' => 'bear']];

    public function onGet($id)
    {
        error_log('*** onGet() method invoked ***');
        $this->body = [
            'name' => isset(self::$data[$id]) ? self::$data[$id]['name'] : '',
            'update' => self::$i++,
        ];

        return $this;
    }

    public function onPatch($id, $name)
    {
        error_log('*** onPatch() method invoked ***');
        self::$data[$id]['name'] = $name;

        return $this;
    }
}
