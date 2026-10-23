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
        $this->mark->enterHole();
        try {
            $view = (string) ($this->request)();
        } finally {
            $this->mark->leaveHole();
        }

        $uri =  (string) $this->request->resourceObject->uri;
        $this->etags->addTag($this->request->resourceObject);
        $this->donutStorage->setView($uri, $view);

        return sprintf(ResourceDonut::FORMAT, $uri);
    }
}
