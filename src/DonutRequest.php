<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use Override;
use Stringable;

use function sprintf;

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
        // A key to scope the mark by, read before invocation: AbstractRequest::__invoke()
        // is what syncs resourceObject->uri->query from the request's own query array, so
        // this read can lack query parameters the final URI below carries - acceptable here
        // (issue #222 tolerates over-refusal when the same path is embedded twice under
        // different query args, the safe side), but not for the placeholder/view mapping,
        // which must be the exact URI ResourceDonut::refresh() later re-fetches by.
        $holeKey = (string) $this->request->resourceObject->uri;
        $this->mark->enterHole($holeKey);
        try {
            $view = (string) ($this->request)();
        } finally {
            $this->mark->leaveHole();
        }

        // Read only now that __invoke() has synced the query string onto it.
        $uri = (string) $this->request->resourceObject->uri;
        $this->etags->addTag($this->request->resourceObject);
        $this->donutStorage->setView($uri, $view);

        return sprintf(ResourceDonut::FORMAT, $uri);
    }
}
