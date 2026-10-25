<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\QueryRepositoryInterface;
use BEAR\QueryRepository\UncacheableMarkInterface;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * CodeRabbit review (thread on f728c8d): a direct, manual `QueryRepository::put($this)` call
 * made from WITHIN this resource's own `onGet()` - not CacheInterceptor's own post-proceed
 * write - is still reached while CacheInterceptor's own write-scope frame is open, tagged
 * with the pre-proceed `$ro` (the SAME instance as `$this` here, since `onGet()` returns
 * `$this`): `isCurrentWriteFor($this)` matches by identity, so `reasonForOwnWrite()` trusts
 * the SAME scoped predicate it trusts for CacheInterceptor's own write, not any-owner.
 * `$markSelf` marks this resource's own (TOP_LEVEL) scope before the direct put, inside the
 * SAME write window the frame opened around - exercising #234's own semantics for this path.
 *
 * `$markAfterPut` exercises Kimi's own ordering instead: the direct put() happens FIRST
 * (storing whatever secret is in the body at that point, since nothing is marked yet), THEN
 * mark() fires - CacheInterceptor's own OUTER write (after proceed() returns) is what
 * actually refuses and cleans up that already-stored secret, not the inner direct put()
 * itself (which legitimately succeeded, nothing was marked at the time it ran).
 */
#[Cacheable]
class DirectPutInsideOwnOnGet extends ResourceObject
{
    public function __construct(
        private readonly QueryRepositoryInterface $queryRepository,
        private readonly UncacheableMarkInterface $mark,
    ) {
    }

    public function onGet(bool $markSelf = false, bool $markAfterPut = false): static
    {
        $this->code = 200;
        $this->body = ['value' => 'direct-put-inside-own-on-get', 'secret' => 'leak-check'];

        if ($markSelf) {
            $this->mark->mark('self-mark');
        }

        $this->queryRepository->put($this);

        if ($markAfterPut) {
            $this->mark->mark('mark-after-put');
        }

        return $this;
    }
}
