<?php

namespace FakeVendor\DemoApp\Resource\App;

use BEAR\RepositoryModule\Annotation\QueryRepository;
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
    }

    public function onGet($id)
    {
        error_log('*** ' . __FUNCTION__ . ' invoked !');

        $this['name'] = isset($this->data[$id]) ? $this->data[$id]['name'] : '';
        $this['time'] = microtime(true);

        return $this;
    }

    public function onPatch($id, $name)
    {
        $this->data[$id]['name'] = $name;

        return $this;
    }
}
