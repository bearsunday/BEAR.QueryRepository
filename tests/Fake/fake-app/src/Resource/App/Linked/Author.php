<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Linked;

use BEAR\Resource\Annotation\Link;
use BEAR\Resource\ResourceObject;

/** Links to the blog its body names */
class Author extends ResourceObject
{
    #[Link(rel: 'blog', href: 'app://self/linked/blog?id={blog_id}', crawl: 'tree')]
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id, 'blog_id' => 12];

        return $this;
    }
}
