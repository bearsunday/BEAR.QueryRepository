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
        private ResourceParamSources $resourceParamSources,
        private CacheDependencyInterface $cacheDependency,
        private CacheTags $cacheTags,
        private CacheIdentityFactory $identityFactory,
        #[CacheLog]
        private SemanticLoggerInterface $logger,
    ) {
    }

    /** @param TagSet $keys the keys the response has before anything is collected */
    public function begin(TagSet $keys): Dependencies
    {
        return new Dependencies($keys, $this->dependsOnTags, $this->resourceParamSources, $this->cacheTags, $this->identityFactory);
    }

    /**
     * Materializes each child first: the keys read from it are set by its own interceptor or onGet(), so they exist
     * only once it has run. AbstractRequest::__toString() memoizes that run for the renderer; __invoke() would execute
     * the child again.
     */
    public function collect(ResourceObject $ro): void
    {
        $dependencies = $this->begin(new TagSet());
        $this->declared($ro);
        foreach (EmbeddedRequests::of($ro) as $request) {
            $this->materialize($request);
            $child = $request->resourceObject;
            $this->depends($ro, $child, $dependencies->read($child));
        }
    }

    /** Only children that already ran are inspected: running one here would execute a hole outside its hole scope */
    public function materials(ResourceObject $ro, Dependencies $dependencies): void
    {
        $this->declared($ro);
        foreach (EmbeddedRequests::of($ro) as $request) {
            $child = $request->resourceObject;
            $this->depends($ro, $child, $dependencies->embedded($child));
        }
    }

    /**
     * A resource a #[ResourceParam] read ran before onGet() and is gone, so the dependency is built from its URI, with the
     * Surrogate-Key of its stored entry when it has one.
     */
    private function declared(ResourceObject $ro): void
    {
        $this->dependsOnTags->declared($ro)?->addTo($ro);
        foreach (($this->resourceParamSources)($ro) as $source) {
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
