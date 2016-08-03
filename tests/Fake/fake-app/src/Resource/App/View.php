<?php

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable(type="view")
 */
class View extends ResourceObject
{
    public function __toString()
    {
        $this->view = 'view';

        return $this->view;
    }

    public function onGet($id)
    {
        $this->body['id'] = $id;
        $this['time'] = microtime(true);

        return $this;
    }
}
