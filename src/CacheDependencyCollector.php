<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\DependencySkippedContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\AbstractRequest;
use BEAR\Resource\Request;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;

/** @internal */
final readonly class CacheDependencyCollector
{
    public function __construct(
        private DependsOnTags $dependsOnTags,
        private CacheDependencyInterface $cacheDependency,
        #[CacheLog]
        private SemanticLoggerInterface $logger,
    ) {
    }

    /**
     * Materializes each child first: the keys read from it are set by its own interceptor or onGet(), so they exist only
     * once it has run. AbstractRequest::__toString() memoizes that run for the renderer; __invoke() would execute the
     * child again.
     */
    public function collect(ResourceObject $ro): void
    {
        $this->dependOnItsOwn($ro);
        foreach ($this->dependsOnTags->requests($ro) as $request) {
            $this->materialize($request);
            $child = $request->resourceObject;
            $this->depends($ro, $child, $this->dependsOnTags->embedded($child));
        }
    }

    /**
     * Picks the children that already ran, a donut page's materials; what they pass up is read once the page has
     * rendered ({@see dependOnMaterials()}), when the requests below them have run too
     *
     * @return list<AbstractRequest>
     */
    public function materials(ResourceObject $ro): array
    {
        $this->dependOnItsOwn($ro);
        $materials = [];
        foreach ($this->dependsOnTags->requests($ro) as $request) {
            $child = $request->resourceObject;
            if ($this->dependsOnTags->hasRun($child)) {
                $materials[] = $request;

                continue;
            }

            $this->depends($ro, $child, $this->dependsOnTags->embedded($child));
        }

        return $materials;
    }

    /** @param list<AbstractRequest> $materials {@see materials()} */
    public function dependOnMaterials(ResourceObject $ro, array $materials, SurrogateKeys $keys): void
    {
        foreach ($materials as $request) {
            $child = $request->resourceObject;
            $this->depends($ro, $child, $keys->embedded($child));
        }
    }

    /**
     * A resource a #[ResourceParam] read ran before onGet() and is gone, so the dependency is built from its URI, with the
     * Surrogate-Key of its stored entry when it has one.
     */
    private function dependOnItsOwn(ResourceObject $ro): void
    {
        ($this->dependsOnTags)($ro);
        foreach ($this->dependsOnTags->resourceParamSources($ro) as $source) {
            $this->cacheDependency->depends($ro, $source);
        }
    }

    private function depends(ResourceObject $ro, ResourceObject $child, bool $depends): void
    {
        if (! $depends) {
            $this->logger->event(DependencySkippedContext::of($ro, $child));

            return;
        }

        $this->cacheDependency->depends($ro, $child);
    }

    /**
     * Runs the child once and renders it, then gives it back the body it returned: its entry in the parent, and whoever
     * reads it through the parent, get that body. A HAL renderer moves every embedded request out of the body it renders.
     */
    private function materialize(AbstractRequest $request): void
    {
        /** @psalm-suppress MixedAssignment */
        $child = $request instanceof Request ? $request->jsonSerialize() : null;
        /** @psalm-suppress MixedAssignment */
        $returned = $child instanceof ResourceObject ? $child->body : null;
        (string) $request;
        if ($child instanceof ResourceObject) {
            $child->body = $returned;
        }
    }
}
