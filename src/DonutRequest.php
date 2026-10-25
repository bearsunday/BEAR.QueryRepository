<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
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
            // second, fresh call through $this->request (which for a plain, unwrapped
            // AbstractRequest would double-execute a non-idempotent child, and for an
            // OnceRequest would still be correct, but there is no reason to prefer a
            // second call over the result already in hand).
            $executed = ($this->request)();
            $view = (string) $executed;
        } finally {
            $this->mark->leaveHole($uri);
        }

        $this->etags->addTag($executed);
        $this->donutStorage->setView($uri, $view);
        if ($this->request instanceof OnceRequest) {
            // Excludes this child from DonutRepository's issue #226 "executed but not
            // rendered" sweep: a rendered hole's tag already landed on $this->etags above,
            // and (by design, see DonutRepository::templateKeys()) a rendered hole's tag
            // stays out of the template's own storage tags either way.
            $this->request->markRendered();
        }

        return ResourceDonut::placeholder($uri);
    }
}
