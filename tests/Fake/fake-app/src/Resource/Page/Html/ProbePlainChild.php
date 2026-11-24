<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\Resource\ResourceObject;

class ProbePlainChild extends ResourceObject
{
    public function onGet(): static
    {
        $this->body['p'] = 'plain';

        return $this;
    }
}
