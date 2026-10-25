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
 * Fable's scenario: like DonutCacheWithMarkingHoleAndCacheableSibling (the literal #234
 * repro - a marking hole, UnstorableValue, before an unrelated #[Cacheable] sibling,
 * InnocentCacheableSibling, that must still be stored), PLUS a direct (non-AOP)
 * `QueryRepository::put()` call made from inside this page's own onGet(), nested inside this
 * page's own rendering. The direct put() has no write-scope frame of its own
 * (`UncacheableMarkInterface::isCurrentWriteFor()` false), so it falls back to the
 * conservative, any-owner `isMarked()` - refused by the unrelated hole's mark, same as
 * 1.17.0/pre-#234 behaviour, not a regression. The AOP-cached sibling, reached through its
 * OWN `CacheInterceptor`-opened scope, stays correctly #234-scoped and is stored regardless.
 */
#[DonutCache]
class DonutPageWithMarkingHoleCacheableSiblingAndDirectPut extends ResourceObject
{
    public function __construct(
        private readonly QueryRepositoryInterface $queryRepository,
    ) {
    }

    #[Embed(rel: 'secret', src: 'app://self/unstorable-value')]
    #[Embed(rel: 'innocent', src: 'app://self/innocent-cacheable-sibling')]
    public function onGet(): static
    {
        // Force the marking hole to execute now, synchronously - its mark is attributed to
        // its own (hole) scope, unrelated to the direct put below.
        $this->body['secret'] = (string) $this->body['secret'];

        $direct = new None();
        $direct->uri = new Uri('app://self/direct-put-after-unrelated-hole-mark');
        $direct->code = 200;
        $direct->body = ['value' => 'unrelated'];
        $this->queryRepository->put($direct);

        $this->body['article'] = '1';

        return $this;
    }
}
