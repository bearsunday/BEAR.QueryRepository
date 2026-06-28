<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\DependsOnContext;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Di\Di\Named;

use function explode;

/**
 * Logging decorator for CacheDependency
 *
 * Emits the depends-on edge as a semantic-log event, keeping CacheDependency itself
 * free of logging. The child tags are read with {@see CacheTags} before delegating,
 * because depends() unsets the child's surrogate-key header as part of wiring the
 * dependency, which would otherwise destroy the inputs needed to report the edge.
 */
final readonly class LoggableCacheDependency implements CacheDependencyInterface
{
    public function __construct(
        #[Named('origin')]
        private CacheDependencyInterface $cacheDependency,
        private SemanticLoggerInterface $logger,
        private CacheTags $cacheTags,
    ) {
    }

    #[Override]
    public function depends(ResourceObject $from, ResourceObject $to): void
    {
        $childTags = $this->cacheTags->childTags($to);
        $this->cacheDependency->depends($from, $to);
        $this->logger->event(new DependsOnContext(
            (string) $from->uri,
            (string) $to->uri,
            explode(' ', $childTags),
        ));
    }
}
