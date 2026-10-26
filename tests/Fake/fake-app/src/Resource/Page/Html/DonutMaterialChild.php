<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] child never carries an ETag - its own write only sets a Surrogate-Key,
 * which is the signal a parent reading this as a material must be classified through.
 */
#[DonutCache]
class DonutMaterialChild extends ResourceObject
{
    public function onGet(): static
    {
        $this->body['value'] = 'donut-material-child-value';

        return $this;
    }
}
