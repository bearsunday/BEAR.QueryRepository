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
class ControlExpiry extends ResourceObject
{
    public $headers = ['Cache-Control' => 'public'];

    public function onGet() : ResourceObject
    {
        $this->body['expiry_at'] = (new \DateTime('+30 sec'))->format('Y-m-d H:i:s'); // NOW + 30 sec

        return $this;
    }
}
