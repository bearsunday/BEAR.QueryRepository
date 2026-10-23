<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\UncacheableMarkInterface;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] page that writes a per-user value straight into its own body/view, outside
 * any embedded hole: the "template holds only placeholders" assumption does not cover this, so
 * the mark must still stop the template write (issue #222).
 */
#[DonutCache]
class UnstorableDonutCacheTemplate extends ResourceObject
{
    public function __construct(
        private readonly UncacheableMarkInterface $mark,
    ) {
    }

    public function onGet(bool $unstorable = true) : ResourceObject
    {
        if ($unstorable) {
            $this->mark->mark('csrf-token-issued');
        }

        $this->body['token'] = 'secret';

        return $this;
    }
}
