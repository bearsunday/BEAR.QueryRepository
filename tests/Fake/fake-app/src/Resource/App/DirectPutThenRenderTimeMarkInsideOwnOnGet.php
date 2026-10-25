<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\QueryRepositoryInterface;
use BEAR\QueryRepository\UncacheableMarkInterface;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\RenderTimeToken;

/**
 * Main's unverified ordering (b): the FULL body (including the render-time-marking token)
 * is set BEFORE the direct put($this) call - not onGet() marking directly, and not a mark
 * added after the put(). ResourceObject::toString() memoizes $this->view once rendered
 * (BEAR.Resource, unrelated to this package): whichever write renders FIRST determines the
 * content EVERY later write/read sees, so putting the token in the body before the FIRST
 * (inner) put() is the only way a render-time mark can reach doPut()'s own gate check at
 * all - adding it only AFTER an already-rendered put() would leave it permanently
 * un-rendered instead (BEAR.Resource's own memoization, not a leak either way).
 * QueryRepository::doPut()'s own render-before-gate-check ordering (type:'view' calls
 * $ro->toString() before reasonForOwnWrite()) means the INNER put() itself sees the mark it
 * just caused and refuses - the same mechanism, unchanged by this round's fixes, that
 * should also refuse the OUTER (AOP) write.
 */
#[Cacheable(type: 'view')]
class DirectPutThenRenderTimeMarkInsideOwnOnGet extends ResourceObject
{
    public function __construct(
        private readonly QueryRepositoryInterface $queryRepository,
        private readonly UncacheableMarkInterface $mark,
    ) {
    }

    public function onGet(): static
    {
        $this->code = 200;
        $this->body = ['value' => 'direct-put-then-render-time-mark', 'token' => new RenderTimeToken($this->mark)];

        $this->queryRepository->put($this);

        return $this;
    }
}
