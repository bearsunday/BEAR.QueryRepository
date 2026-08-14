<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class MutableComment extends ResourceObject
{
    public static string $comment = 'comment-a';

    public function onGet(): static
    {
        $this->body = ['comment' => self::$comment];
        $this->headers[Header::SURROGATE_KEY] = 'mutable-comment';

        return $this;
    }
}
