<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\ResourceObject;
use Override;
use Stringable;

/**
 * Stands in for an embedded `#[Embed]` request while a donut template is composed
 *
 * `__toString()` is where that hole's own `onGet()` actually runs (`(string) $ro` on the
 * composing page calls this during template rendering): a mark() the hole makes during that
 * run is scoped to it, not attributed to the composing page (issue #222, `UncacheableMark`).
 */
final readonly class DonutRequest implements Stringable
{
    public function __construct(
        private AbstractRequest $request,
        private DonutRendererInterface $donutStorage,
        private SurrogateKeys $etags,
        private UncacheableMarkInterface $mark,
    ) {
    }

    #[Override]
    public function __toString(): string
    {
        // toUri() performs the same resourceObject->uri->query sync __invoke() does (its
        // first statement), without invoking - so this already carries the full query
        // string before the hole runs, usable both as enterHole()'s key and, unchanged,
        // for the placeholder/view mapping that ResourceDonut::refresh() later re-fetches
        // by (issue #222: a query-less key here would also let two sibling holes on the
        // same path but different query args collide on one owner slot). Verified equal to
        // the executed object's own URI below: __invoke()'s first statement does the exact
        // same sync from the exact same $this->query, so the two can never disagree.
        $uri = $this->request->toUri();
        $this->mark->enterHole($uri);
        try {
            // The single execution this hole's content and tags both come from - not a
            // second, fresh call through $this->request (which for a plain AbstractRequest
            // would double-execute a non-idempotent child, see issue #226). jsonSerialize()
            // is AbstractRequest's own public accessor for the private, memoizing invoke()
            // that offsetGet()/__get()/__toString()/getIterator() already funnel through -
            // so a child the page's own onGet() already read answers from that same
            // execution here, and a child nothing has read yet runs for the first (and
            // only) time right now. A subclass may override jsonSerialize() to mean
            // something else (BEAR\Async\AsyncRequest decodes an already-flushed JSON view
            // into an array instead - see ResourceBodyEvaluator::materialize()'s own
            // identical check), so the result is trusted only when it genuinely is the
            // memoized ResourceObject; anything else falls back to invoking the request
            // directly, exactly as before this fix.
            /** @psalm-suppress MixedAssignment $memo - mixed when jsonSerialize() answers something other than the memo */
            $memo = $this->request->jsonSerialize();
            $executed = $memo instanceof ResourceObject ? $memo : ($this->request)();
            // toString(), not (string) $executed: the magic __toString() swallows a
            // Throwable from rendering into an E_USER_WARNING and '' - storing that empty
            // placeholder content would make a #[CacheableResponse] view persist a hole
            // that failed. toString() has no such catch, so the Throwable propagates
            // through this __toString() (PHP 8 allows one to throw), through the
            // template engine (Twig re-wraps it as a Twig\Error\RuntimeError), up to
            // ResourceDonut::create()'s own non-swallowing render of the composing page.
            $view = $executed->toString();
        } finally {
            $this->mark->leaveHole($uri);
        }

        $this->etags->addTag($executed);
        $this->donutStorage->setView($uri, $view);

        return ResourceDonut::placeholder($uri);
    }
}
