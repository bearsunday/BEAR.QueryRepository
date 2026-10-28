<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\ResourceObject;

/**
 * A #[DonutCache] page that writes a per-user value straight into its own body/view, outside
 * any embedded hole: the "template holds only placeholders" assumption does not cover this,
 * so a runtime-private Cache-Control must still stop the template write.
 */
#[DonutCache]
class UnstorableDonutCacheTemplate extends ResourceObject
{
    public function onGet(bool $unstorable = true): ResourceObject
    {
        if ($unstorable) {
            $this->headers[Header::CACHE_CONTROL] = 'private';
        }

        $this->body['token'] = 'secret';

        return $this;
    }
}
