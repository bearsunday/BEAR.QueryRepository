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
use function explode;
use function implode;
use function sprintf;

final readonly class CacheDependency implements CacheDependencyInterface
{
    public function __construct(
        private UriTagInterface $uriTag,
        #[CacheLog]
        private SemanticLoggerInterface $logger = new NullSemanticLogger(),
    ) {
    }

    #[Override]
    public function depends(ResourceObject $from, ResourceObject $to): void
    {
        $childTags = ($this->uriTag)($to->uri);
        if (isset($to->headers[Header::SURROGATE_KEY])) {
            $childTags .= sprintf(' %s', $to->headers[Header::SURROGATE_KEY]);
            unset($to->headers[Header::SURROGATE_KEY]);
        }

        // Accumulate across embedded children, deduped: #[Refresh] re-puts the instance
        // the rebuilding GET already stored, and this header ships to the CDN.
        $existing = isset($from->headers[Header::SURROGATE_KEY])
            ? explode(' ', $from->headers[Header::SURROGATE_KEY])
            : [];
        $from->headers[Header::SURROGATE_KEY] = implode(' ', array_unique([...$existing, ...explode(' ', $childTags)]));

        $this->logger->event(new DependsOnContext(
            (string) $from->uri,
            (string) $to->uri,
            explode(' ', $childTags),
        ));
    }
}
