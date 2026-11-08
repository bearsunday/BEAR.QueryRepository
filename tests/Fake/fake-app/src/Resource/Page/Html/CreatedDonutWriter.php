<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;

/** onGet adds to the body, so a refresh on the written object would keep the write's keys */
#[CacheableResponse]
class CreatedDonutWriter extends ResourceObject
{
    public static int $gets = 0;

    public function onGet(int $id): static
    {
        self::$gets++;
        $this->body['id'] = $id;
        $this->body['generation'] = self::$gets;

        return $this;
    }

    public function onPut(int $id, string $title): static
    {
        $this->code = Code::CREATED;
        $this->headers['Location'] = '/html/created-donut-writer?id=' . $id;
        $this->body = ['id' => $id, 'title' => $title];

        return $this;
    }
}
