<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable
 */
#[Cacheable(type: 'view')]
class Like extends ResourceObject
{
    public function onGet()
    {
        $this->body = [
                'like' => 'like0'
            ];

            return $this;
        }
}