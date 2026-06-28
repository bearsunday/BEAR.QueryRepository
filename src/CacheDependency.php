<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Override;

use function assert;
use function sprintf;

final class CacheDependency implements CacheDependencyInterface
{
    public function __construct(
        private readonly UriTagInterface $uriTag,
        private readonly RepositoryLoggerInterface $logger,
    ) {
    }

    #[Override]
    public function depends(ResourceObject $from, ResourceObject $to): void
    {
        assert(! isset($from->headers[Header::SURROGATE_KEY]));

        $cacheDependencyTags = ($this->uriTag)($to->uri);
        if (isset($to->headers[Header::SURROGATE_KEY])) {
            $cacheDependencyTags .= sprintf(' %s', $to->headers[Header::SURROGATE_KEY]);
            unset($to->headers[Header::SURROGATE_KEY]);
        }

        $from->headers[Header::SURROGATE_KEY] = $cacheDependencyTags;
        $this->logger->log('depends from:%s to:%s tags:%s', $from->uri, $to->uri, $cacheDependencyTags);
    }
}
