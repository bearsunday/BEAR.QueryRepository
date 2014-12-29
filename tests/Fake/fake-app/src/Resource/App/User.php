<?php

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\QueryRepository;
use BEAR\RepositoryModule\Annotation\Reload;
use BEAR\Resource\ResourceObject;

/**
 * @QueryRepository
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
     * @Reload(uri="app://self/user/profile?user_id={id}")
     */
    public function onPut($id, $name, $age)
    {
        $this->data[$id]['name'] = $name;
        $this->data[$id]['age'] = $age;

        return $this;
    }

    public function onDelete($id)
    {
        unset($this->data[$id]);

        return $this;
    }
}
