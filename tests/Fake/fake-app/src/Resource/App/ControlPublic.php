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
 * @Cacheable(expirySecond=60)
 */
class ControlPublic extends ResourceObject
{
    public function onGet() : ResourceObject
    {
        $this->headers = ['Cache-Control' => 'public'];

        return $this;
    }
}
