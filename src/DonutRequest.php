<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\Request;
use BEAR\Resource\ResourceObject;
use Override;
use Stringable;

use function assert;

/**
 * Runs an embedded hole while a donut template renders; a mark() made then is scoped to the hole (#222)
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
        // Includes the query, so sibling holes on one path get separate owner slots (#222)
        $uri = $this->request->toUri();
        $this->mark->enterHole($uri);
        try {
            // A Request answers its memoized result, so a child onGet() already read is not run again (#226).
            // Other requests are invoked directly: AsyncRequest's jsonSerialize() flushes its batch.
            /** @psalm-suppress MixedAssignment $executed - Request::jsonSerialize() answers its memoized ResourceObject */
            $executed = $this->request instanceof Request ? $this->request->jsonSerialize() : ($this->request)();
            assert($executed instanceof ResourceObject);
            // Not (string): the cast turns a render failure into '', which would be stored (#237)
            $view = $executed->toString();
        } finally {
            $this->mark->leaveHole($uri);
        }

        $this->etags->addTag($executed);
        $this->donutStorage->setView($uri, $view);

        return ResourceDonut::placeholder($uri);
    }
}
