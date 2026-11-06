<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Link;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;

/** onGet adds to the body, so a refresh on the written object would keep the write's keys */
#[Cacheable]
class StatusWriter extends ResourceObject
{
    public static int $gets = 0;

    public function onGet(string $id): static
    {
        self::$gets++;
        $this->body['id'] = $id;
        $this->body['generation'] = self::$gets;

        return $this;
    }

    #[Link(rel: 'done', href: 'app://self/refresh-dest{?id}')]
    public function onPut(string $id, string $title): static
    {
        $this->code = Code::CREATED;
        $this->headers['Location'] = '/status-writer?id=' . $id;
        $this->body = ['id' => $id, 'title' => $title];

        return $this;
    }

    public function onDelete(string $id): static
    {
        unset($id);
        $this->code = Code::NO_CONTENT;

        return $this;
    }
}
