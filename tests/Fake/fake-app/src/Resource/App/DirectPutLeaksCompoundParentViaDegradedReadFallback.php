<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\QueryRepositoryInterface;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\None;

/**
 * The compound-parent leak scenario: a direct (non-AOP) `QueryRepository::put()` call, made from inside
 * this resource's own `onGet()` while it is reached via `CacheInterceptor`'s own degraded-read
 * fallback (no write scope of its own - the fallback never calls `put()` for THIS resource
 * itself), for a SEPARATE "compound parent" object that embeds an independently
 * donut-composed hole's ACTUAL secret value (rendered now, synchronously - not a storage
 * placeholder). With no write-scope frame of its own for that direct put(), the old
 * `wasMarkedSinceCurrentWrite()` stayed silent (nothing to compare against) and
 * `isMarkedForCurrentScope()` never saw the hole's own-owner mark either - the parent was
 * stored WITH the leaked secret. `UncacheableMarkInterface::isCurrentWriteFor()` fixes this:
 * no frame matches the parent's own URI, so its write falls back to conservative, any-owner
 * `isMarked()` instead, which does catch it.
 */
#[Cacheable]
class DirectPutLeaksCompoundParentViaDegradedReadFallback extends ResourceObject
{
    public function __construct(
        private readonly QueryRepositoryInterface $queryRepository,
    ) {
    }

    #[Embed(rel: 'donut', src: 'page://self/html/donut-cache-with-unstorable-hole')]
    public function onGet(): static
    {
        // Force the independently donut-composed page to render NOW: its own hole's ACTUAL
        // secret is baked into this string, the same way a type:'view' write-time render
        // bakes it into CacheableViewEmbeddingMarkingDonut's own template.
        $rendered = (string) $this->body['donut'];

        $parent = new None();
        $parent->uri = new Uri('app://self/direct-put-compound-parent-cache-interceptor');
        $parent->code = 200;
        $parent->body = ['rendered' => $rendered];
        $this->queryRepository->put($parent);

        $this->body['article'] = '1';

        return $this;
    }
}
