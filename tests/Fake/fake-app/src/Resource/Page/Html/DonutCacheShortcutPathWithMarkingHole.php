<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\ResourceObject;

/**
 * Fable's finding: presets its own ETag (AbstractDonutCacheInterceptor's "already answered"
 * shortcut, same as SelfEtag) - the twin of PlainDonutHole used for the CONTROL (normal
 * write path) side of the same test, so both reach AbstractDonutCacheInterceptor with an
 * otherwise-identical body/response, differing only in which path handles them.
 */
#[DonutCache]
class DonutCacheShortcutPathWithMarkingHole extends ResourceObject
{
    public function onGet(): static
    {
        $this->body['value'] = 'plain';
        $this->headers[Header::ETAG] = '"donut-shortcut-path-self-etag"';

        return $this;
    }
}
