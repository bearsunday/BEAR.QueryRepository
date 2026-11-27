<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\DependsOnContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;

/** @internal */
final readonly class CacheDependency implements CacheDependencyInterface
{
    public function __construct(
        private CacheTags $cacheTags,
        #[CacheLog]
        private SemanticLoggerInterface $logger,
    ) {
    }

    #[Override]
    public function depends(ResourceObject $from, ResourceObject $to): void
    {
        $childTags = $this->cacheTags->tags($to);
        $childTags->addTo($from);

        $this->logger->event(new DependsOnContext(
            (string) $from->uri,
            (string) $to->uri,
            $childTags->toArray(),
        ));
    }
}
