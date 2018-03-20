<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\DemoApp\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable(expirySecond=300)
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
        error_log(__FUNCTION__ . ' invoked');

        $this['name'] = isset($this->data[$id]) ? $this->data[$id]['name'] : '';
        $this['rnd'] = mt_rand(1, 100);

        return $this;
    }

    public function onPatch($id, $name)
    {
        $this->data[$id]['name'] = $name;

        return $this;
    }
}
