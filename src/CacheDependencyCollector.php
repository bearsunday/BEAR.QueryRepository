<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\DependencySkippedContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
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
     * Null $materialKeys materializes each child first: the keys read from it are set by its own
     * interceptor or onGet(), so they exist only once it has run. AbstractRequest::__toString()
     * memoizes that run for the renderer; __invoke() would execute the child again.
     *
     * With $materialKeys (donut), only children that already ran are inspected.
     */
    public function collect(
        ResourceObject $ro,
        SurrogateKeys|null $materialKeys = null,
    ): void {
        ($this->dependsOnTags)($ro);
        foreach ($this->dependsOnTags->requests($ro) as $request) {
            if ($materialKeys === null) {
                (string) $request;
            }

            $child = $request->resourceObject;
            $depends = $materialKeys === null ? $this->dependsOnTags->embedded($child) : $materialKeys->embedded($child);
            if (! $depends) {
                $this->logger->event(DependencySkippedContext::of($ro, $child));

                continue;
            }

            $this->cacheDependency->depends($ro, $child);
        }
    }
}
