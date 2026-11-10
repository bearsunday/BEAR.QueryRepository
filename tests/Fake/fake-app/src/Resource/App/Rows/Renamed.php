<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** Rows keyed `comment_id`, mapped to the row resource's `id` argument in the template */
#[Cacheable]
class Renamed extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/comment?id={comment_id}')]
    public function onGet(): static
    {
        $this->body = [['comment_id' => '1'], ['comment_id' => '3']];

        return $this;
    }
}
