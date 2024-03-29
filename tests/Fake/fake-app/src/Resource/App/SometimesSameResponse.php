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
 * @Cacheable
 */
#[Cacheable]
class SometimesSameResponse extends ResourceObject
{
    private array $data = [
        1 => 'same message',
        2 => 'same message',
        3 => 'same message',
        4 => 'different message',
    ];

    public function onGet($id)
    {
        $this['message'] = $this->data[$id] ?? '';

        return $this;
    }

    public function onDelete($id)
    {
        unset($this->data[$id]);

        return $this;
    }
}
