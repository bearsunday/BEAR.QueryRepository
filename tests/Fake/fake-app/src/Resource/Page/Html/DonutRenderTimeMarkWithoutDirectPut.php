<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\UncacheableMarkInterface;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\RenderTimeToken;

/**
 * The control for DonutDirectPutThenRenderTimeMarkInsideOwnOnGet: the SAME
 * render-time-marking token, with NO inner put() at all - so no ETag side effect, no
 * shortcut, and the normal write path (putRecorded() -> doPutDonut() -> donut->render())
 * is what actually stringifies the token and marks, the same as it always has.
 */
#[DonutCache]
class DonutRenderTimeMarkWithoutDirectPut extends ResourceObject
{
    public function __construct(
        private readonly UncacheableMarkInterface $mark,
    ) {
    }

    public function onGet(): static
    {
        $this->code = 200;
        $this->body = ['value' => 'donut-render-time-mark-without-direct-put', 'token' => new RenderTimeToken($this->mark)];

        return $this;
    }
}
