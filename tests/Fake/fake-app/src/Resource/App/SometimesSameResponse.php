<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class SometimesSameResponse extends ResourceObject
{
    public const DATA = [
        1 => 'same message',
        2 => 'same message',
        3 => 'same message',
        4 => 'different message',
    ];

    /** Static, so the new instance the refresh after a write creates sees the write */
    public static array $data = self::DATA;

    public function onGet($id)
    {
        $this['message'] = self::$data[$id] ?? '';

        return $this;
    }

    public function onDelete($id)
    {
        unset(self::$data[$id]);

        return $this;
    }
}
