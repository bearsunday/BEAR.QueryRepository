<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\QueryRepository\Header;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\ResourceObject;

/**
 * The ETag-shortcut case: presets its own ETag (AbstractDonutCacheInterceptor's "already answered"
 * shortcut, same as SelfEtag) - the twin of PlainDonutHole used for the CONTROL (normal
 * write path) side of the same test, so both reach AbstractDonutCacheInterceptor with an
 * otherwise-identical body/response, differing only in which path handles them.
 *
 * Carries no embedded hole of its own: a hole scope (UncacheableMarkScopeInterface::
 * enterHole()/leaveHole()) is only ever opened by DonutRequest, created solely inside
 * ResourceDonut::create() - the normal WRITE path this shortcut specifically bypasses, so
 * no #[Embed] on this fixture could ever actually open one here. The test using this
 * fixture supplies a hole-scoped mark synthetically (its own enterHole()/mark()/
 * leaveHole() before the request), standing in for an EARLIER, separate hole elsewhere in
 * the same request tree having already marked and closed - a reachable, #234-style
 * "unrelated sibling marked and closed beforehand" scenario, not a hole embedded in THIS
 * page.
 */
#[DonutCache]
class DonutCacheShortcutPathSelfEtag extends ResourceObject
{
    public function onGet(): static
    {
        $this->body['value'] = 'plain';
        $this->headers[Header::ETAG] = '"donut-shortcut-path-self-etag"';

        return $this;
    }
}
