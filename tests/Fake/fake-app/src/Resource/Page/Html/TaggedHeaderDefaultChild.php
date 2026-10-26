<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * Declares Surrogate-Key as a $headers property default rather than setting it inside
 * onGet() - present on the object from construction, before onGet() ever runs. Pins the
 * known limit: a parent that only embeds this as a hole (never reads it) sees the header
 * already set and misclassifies it as a material.
 */
#[Cacheable]
class TaggedHeaderDefaultChild extends ResourceObject
{
    /** @var array<string, string> */
    public $headers = [Header::SURROGATE_KEY => 'tagged-header-default-child'];

    public function onGet(): static
    {
        $this->body = ['value' => 'tagged-header-default'];

        return $this;
    }
}
