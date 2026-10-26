<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\QueryRepositoryInterface;
use BEAR\QueryRepository\UncacheableMarkInterface;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\RenderTimeToken;

/**
 * The render-time-mark ordering, donut side: onGet() calls put($this) directly, with a
 * render-time-marking token already in the body. Verified by execution (not by reading the
 * code) that this ordering is structurally unreachable for a #[DonutCache] page, the same
 * as App\DirectPutThenRenderTimeMarkInsideOwnOnGet is for #[Cacheable] but for a different
 * reason: QueryRepository::doPut()'s $storesView check only recognizes #[Cacheable]
 * (getCacheableAnnotation()), so the direct put()'s own render never runs for a
 * #[DonutCache]-only resource - but its headerSetter still sets an ETag unconditionally
 * (the SAME side effect the put($this)-then-mark() leak fix addresses), which reaches
 * AbstractDonutCacheInterceptor's own "already answered" shortcut BEFORE putRecorded()/
 * doPutDonut() (the ONLY place that would ever stringify the token) could run. mark() is
 * never called either way: real observed result (both normal and degraded-read-fallback
 * paths, identical) - no exception, isMarked() false, stored with the token an
 * UNSTRINGIFIED object (body: {"value":...,"token":{}}), Cache-Control absent (etag-present
 * shortcut, not unstorable), event sequence ...save_value, put_skipped(etag-present) - no
 * put_donut event at all. DonutRenderTimeMarkWithoutDirectPut is the control: the SAME
 * token, no inner put() at all, correctly marks and is correctly refused via the normal,
 * unchanged doPutDonut() path.
 */
#[DonutCache]
class DonutDirectPutThenRenderTimeMarkInsideOwnOnGet extends ResourceObject
{
    public function __construct(
        private readonly QueryRepositoryInterface $queryRepository,
        private readonly UncacheableMarkInterface $mark,
    ) {
    }

    public function onGet(): static
    {
        $this->code = 200;
        $this->body = ['value' => 'donut-direct-put-then-render-time-mark', 'token' => new RenderTimeToken($this->mark)];

        $this->queryRepository->put($this);

        return $this;
    }
}
