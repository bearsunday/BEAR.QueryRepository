<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\Request;
use BEAR\Resource\ResourceObject;
use JsonSerializable;
use Override;
use Stringable;

use function assert;

/**
 * Runs an embedded hole while a donut template renders
 *
 * @internal
 */
final class DonutRequest implements Stringable, JsonSerializable
{
    /** Whether the renderer encoded this hole as JSON, where no placeholder can stand for it */
    public bool $isEncoded = false;

    public function __construct(
        public readonly AbstractRequest $request,
        private readonly DonutRendererInterface $donutStorage,
        private readonly SurrogateKeys $etags,
    ) {
    }

    /**
     * Runs the hole and answers its resource
     *
     * A Request answers its memoized result, so a child onGet() already read is not run again (#226). Other requests
     * are invoked directly: bear/async's AsyncRequest keeps its result only for its own rendering, and that rendering
     * uses a result it was invoked for instead of running the request again.
     */
    public static function resultOf(AbstractRequest $request): ResourceObject
    {
        /** @psalm-suppress MixedAssignment $executed - Request::jsonSerialize() answers its memoized ResourceObject */
        $executed = $request instanceof Request ? $request->jsonSerialize() : $request();
        assert($executed instanceof ResourceObject);

        return $executed;
    }

    #[Override]
    public function __toString(): string
    {
        // Includes the query, so sibling holes on one path get distinct cache entries
        $uri = $this->request->toUri();
        $executed = self::resultOf($this->request);
        // Not (string): the cast turns a render failure into '', which would be stored (#237)
        $view = $executed->toString();

        $this->etags->addTag($executed);
        $this->donutStorage->setView($uri, $view);

        return ResourceDonut::placeholder($uri);
    }

    #[Override]
    public function jsonSerialize(): null
    {
        $this->isEncoded = true;

        return null;
    }
}
