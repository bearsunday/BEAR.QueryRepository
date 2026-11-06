<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\FakeAnnotation;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class User extends ResourceObject
{
    public const DATA = [1 => ['name' => 'bear', 'age' => '3']];

    /**
     * Static, so the new instance the refresh after a write creates sees the write
     *
     * @var array<int, array<string, string>>
     */
    public static $data = self::DATA;

    public function onGet($id)
    {
        $this['name'] = isset(self::$data[$id]) ? self::$data[$id]['name'] : '';
        $this['time'] = \microtime(true);

        return $this;
    }

    public function onPatch($id, $name)
    {
        if (empty($name)) {
            $this->code = Code::BAD_REQUEST;

            return $this;
        }

        self::$data[$id]['name'] = $name;

        return $this;
    }

    #[Purge(uri: "app://self/user/friend?user_id={id}")]
    #[Refresh(uri: "app://self/user/profile?user_id={id}")]
    #[FakeAnnotation]
    public function onPut($id, $name, $age)
    {
        self::$data[$id]['name'] = $name;
        self::$data[$id]['age'] = $age;
        $this['id'] = $id;

        return $this;
    }

    public function onDelete($id)
    {
        unset(self::$data[$id]);

        return $this;
    }
}
