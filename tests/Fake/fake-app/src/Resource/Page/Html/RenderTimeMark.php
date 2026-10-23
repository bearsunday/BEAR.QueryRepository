<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\UncacheableMarkInterface;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\RenderTimeToken;

/**
 * Stands in for a #[DonutCache] page whose template embeds a value (e.g. a Twig CSRF helper)
 * that marks the response unstorable only when stringified during the page's own template
 * render - not in onGet(), and not inside any embedded hole's execution (issue #222).
 */
#[DonutCache]
class RenderTimeMark extends ResourceObject
{
    public function __construct(
        private readonly UncacheableMarkInterface $mark,
    ) {
    }

    public function onGet() : ResourceObject
    {
        // Not marked here: the mark happens only when {{ token }} is stringified below,
        // during ResourceDonut::create()'s (string) $ro - the same call that resolves any
        // embedded hole, but this value is not one.
        $this->body['token'] = new RenderTimeToken($this->mark);

        return $this;
    }
}
