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
 * @Cacheable(expiryAt="expiry_at")
 */
class ControlExpiryError extends ResourceObject
{
    public function onGet() : ResourceObject
    {
        return $this;
    }
}
