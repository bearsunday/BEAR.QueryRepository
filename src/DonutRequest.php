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
        // same path but different query args collide on one owner slot).
        $uri = $this->request->toUri();
        $this->mark->enterHole($uri);
        try {
            $view = (string) ($this->request)();
        } finally {
            $this->mark->leaveHole($uri);
        }

        $this->etags->addTag($this->request->resourceObject);
        $this->donutStorage->setView($uri, $view);

        return ResourceDonut::placeholder($uri);
    }
}
