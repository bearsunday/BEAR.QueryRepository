<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\QueryRepositoryInterface;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

/**
 * The donut-interceptor twin of App\DirectPutThenChildMarksInsideOwnOnGet (Main's unverified
 * ordering (a)): onGet() calls put($this) directly (stored, nothing marked yet - the write's
 * own headerSetter sets an ETag as a side effect, triggering AbstractDonutCacheInterceptor's
 * own "already answered" shortcut), THEN explicitly fetches a NESTED CHILD resource
 * (app://self/unstorable-value) that marks itself - not onGet() marking directly.
 */
#[DonutCache]
class DonutDirectPutThenChildMarksInsideOwnOnGet extends ResourceObject
{
    public function __construct(
        private readonly QueryRepositoryInterface $queryRepository,
        private readonly ResourceInterface $resource,
    ) {
    }

    public function onGet(): static
    {
        $this->code = 200;
        $this->body = ['value' => 'donut-direct-put-then-child-marks', 'secret' => 'leak-check'];

        $this->queryRepository->put($this);

        $child = $this->resource->get('app://self/unstorable-value');
        $this->body['child'] = $child->body['token'];

        return $this;
    }
}
