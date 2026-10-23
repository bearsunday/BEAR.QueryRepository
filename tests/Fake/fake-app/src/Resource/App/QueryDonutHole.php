<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\UncacheableMarkInterface;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] hole whose own template-write check is scoped to its own owner slot
 * (issue #222 follow-up): a sibling with the same path but a different query must not
 * share that slot, or marking one refuses the other's otherwise-unmarked template write too.
 */
#[DonutCache]
class QueryDonutHole extends ResourceObject
{
    public function __construct(
        private readonly UncacheableMarkInterface $mark,
    ) {
    }

    public function onGet(string $token = '', string $visibility = 'public') : ResourceObject
    {
        if ($visibility === 'private') {
            $this->mark->mark('query-donut-hole-marked');
        }

        $this->body = ['token' => $token];

        return $this;
    }
}
