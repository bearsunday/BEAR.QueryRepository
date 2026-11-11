<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\DependsOnContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;

use function array_unique;
use function array_values;
use function explode;
use function implode;

/** @internal */
final readonly class CacheDependency implements CacheDependencyInterface
{
    private CacheTags $cacheTags;

    public function __construct(
        UriTagInterface $uriTag,
        #[CacheLog]
        private SemanticLoggerInterface $logger = new NullSemanticLogger(),
        BaseUriTagInterface|null $baseUriTag = null,
        CacheTags|null $cacheTags = null,
    ) {
        $this->cacheTags = $cacheTags ?? new CacheTags($uriTag, $baseUriTag ?? new BaseUriTag($uriTag));
    }

    #[Override]
    public function depends(ResourceObject $from, ResourceObject $to): void
    {
        $childTagList = array_values(array_unique(explode(' ', implode(' ', $this->cacheTags->tags($to)))));

        // Accumulate across embedded children, deduped: #[Refresh] re-puts the instance
        // the rebuilding GET already stored, and this header ships to the CDN.
        $existing = isset($from->headers[Header::SURROGATE_KEY])
            ? explode(' ', $from->headers[Header::SURROGATE_KEY])
            : [];
        $from->headers[Header::SURROGATE_KEY] = implode(' ', array_unique([...$existing, ...$childTagList]));

        $this->logger->event(new DependsOnContext(
            (string) $from->uri,
            (string) $to->uri,
            $childTagList,
        ));
    }
}
