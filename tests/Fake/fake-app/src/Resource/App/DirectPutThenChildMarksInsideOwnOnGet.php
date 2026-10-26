<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\QueryRepositoryInterface;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * The nested-child-mark ordering: onGet() calls put($this) directly (stored - nothing
 * marked yet), THEN forces a NESTED CHILD resource (app://self/unstorable-value, an
 * #[Embed], lazy until cast/accessed) to execute and mark itself - not onGet() marking
 * directly, not a render-time mark. The child's mark happens inside the SAME write-scope
 * window CacheInterceptor's own enterWrite() opened around proceed() (before onGet() ran),
 * so wasMarkedSinceCurrentWrite() should catch it for the OUTER write the same way a
 * direct mark() call inside onGet() itself does.
 */
#[Cacheable]
class DirectPutThenChildMarksInsideOwnOnGet extends ResourceObject
{
    public function __construct(
        private readonly QueryRepositoryInterface $queryRepository,
    ) {
    }

    #[Embed(rel: 'child', src: 'app://self/unstorable-value')]
    public function onGet(): static
    {
        $this->code = 200;
        // Set individual keys, not $this->body = [...] - a wholesale replace would wipe
        // out the #[Embed]-injected Request object #[Embed] already placed at
        // $this->body['child'] BEFORE onGet() started running, leaving the later cast
        // below stringify null instead (empty, no error, no mark at all).
        $this->body['value'] = 'direct-put-then-child-marks';
        $this->body['secret'] = 'leak-check';

        $this->queryRepository->put($this);

        // Force the lazy #[Embed] to execute NOW, marking - after the direct put() above.
        $this->body['child'] = (string) $this->body['child'];

        return $this;
    }
}
