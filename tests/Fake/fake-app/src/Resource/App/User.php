<?php

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable
 */
class User extends ResourceObject
{
    private $data = [];

    public function __construct()
    {
        $this->data[1]['name'] = 'bear';
        $this->data[1]['age'] = '3';
    }

    public function onGet($id)
    {
        $this['name'] = isset($this->data[$id]) ? $this->data[$id]['name'] : '';
        $this['time'] = microtime(true);

        return $this;
    }

    public function onPatch($id, $name)
    {
        $this->data[$id]['name'] = $name;

        return $this;
    }

    /**
     * @Purge(uri="app://self/user/friend?user_id={id}")
     * @Refresh(uri="app://self/user/profile?user_id={id}")
     */
    public function onPut($id, $name, $age)
    {
        $this->data[$id]['name'] = $name;
        $this->data[$id]['age'] = $age;
        $this['id'] = $id;

        return $this;
    }

    public function onDelete($id)
    {
        unset($this->data[$id]);

        return $this;
    }
}
