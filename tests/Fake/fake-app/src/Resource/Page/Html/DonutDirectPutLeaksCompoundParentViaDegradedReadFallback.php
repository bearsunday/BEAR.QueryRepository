<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\QueryRepositoryInterface;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\None;

/**
 * The donut-interceptor twin of App\DirectPutLeaksCompoundParentViaDegradedReadFallback: the
 * same direct (non-AOP) put() of a compound parent, made from inside this page's own onGet(),
 * reached via AbstractDonutCacheInterceptor's own degraded-read fallback - which, like
 * CacheInterceptor's, opens no write scope of its own.
 */
#[DonutCache]
class DonutDirectPutLeaksCompoundParentViaDegradedReadFallback extends ResourceObject
{
    public function __construct(
        private readonly QueryRepositoryInterface $queryRepository,
    ) {
    }

    #[Embed(rel: 'donut', src: 'page://self/html/donut-cache-with-unstorable-hole')]
    public function onGet(): static
    {
        $rendered = (string) $this->body['donut'];

        $parent = new None();
        $parent->uri = new Uri('app://self/direct-put-compound-parent-donut-fallback');
        $parent->code = 200;
        $parent->body = ['rendered' => $rendered];
        $this->queryRepository->put($parent);

        $this->body['article'] = '1';

        return $this;
    }
}
