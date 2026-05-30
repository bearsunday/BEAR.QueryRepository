<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Override;

use function assert;
use function explode;
use function sprintf;

final readonly class CacheDependency implements CacheDependencyInterface
{
    public function __construct(
        private UriTagInterface $uriTag,
        private RepositoryLoggerInterface $logger = new NullRepositoryLogger(),
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
        $this->logger->log('depends-on', [
            'parent' => (string) $from->uri,
            'child' => (string) $to->uri,
            'childTags' => explode(' ', $cacheDependencyTags),
        ]);
    }
}
