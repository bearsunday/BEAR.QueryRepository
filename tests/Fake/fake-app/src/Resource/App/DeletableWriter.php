<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class DeletableWriter extends ResourceObject
{
    public const DATA = [1 => 'one'];

    /** @var array<int|string, string> */
    public static array $data = self::DATA;

    public function onGet(string $id): static
    {
        if (! isset(self::$data[$id])) {
            $this->code = Code::NOT_FOUND;

            return $this;
        }

        $this->body = ['id' => $id, 'name' => self::$data[$id]];

        return $this;
    }

    public function onDelete(string $id): static
    {
        unset(self::$data[$id]);
        $this->code = Code::NO_CONTENT;

        return $this;
    }
}
