<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\QueryRepositoryInterface;
use BEAR\QueryRepository\UncacheableMarkInterface;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\ResourceObject;

/**
 * The donut-interceptor twin of App\DirectPutInsideOwnOnGet's own `$markAfterPut` scenario:
 * a direct (non-AOP) `QueryRepository::put($this)` call made from WITHIN this page's own
 * `onGet()`, storing whatever secret is in the body at that point (nothing marked yet, so it
 * legitimately succeeds), followed by `mark()` - exercising Kimi's own leak ordering for
 * AbstractDonutCacheInterceptor's own degraded-read fallback, not just CacheInterceptor's.
 */
#[DonutCache]
class DonutDirectPutThenMarkInsideOwnOnGet extends ResourceObject
{
    public function __construct(
        private readonly QueryRepositoryInterface $queryRepository,
        private readonly UncacheableMarkInterface $mark,
    ) {
    }

    public function onGet(bool $markAfterPut = false): static
    {
        $this->code = 200;
        $this->body = ['value' => 'donut-direct-put-then-mark', 'secret' => 'leak-check'];

        $this->queryRepository->put($this);

        if ($markAfterPut) {
            $this->mark->mark('mark-after-put');
        }

        return $this;
    }
}
