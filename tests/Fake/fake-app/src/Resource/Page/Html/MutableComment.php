<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\Header;
use BEAR\QueryRepository\UncacheableMarkInterface;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class MutableComment extends ResourceObject
{
    public static string $comment = 'comment-a';
    /** Marks this recomposition unstorable, the way a CSRF token issuer would mid-render */
    public static bool $markUnstorable = false;

    public function __construct(
        private readonly UncacheableMarkInterface $mark,
    ) {
    }

    public function onGet(): static
    {
        if (self::$markUnstorable) {
            $this->mark->mark('csrf-token-issued');
        }

        $this->body = ['comment' => self::$comment];
        $this->headers[Header::SURROGATE_KEY] = 'mutable-comment';

        return $this;
    }
}
