<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Override;

use function sprintf;

final readonly class CacheDependency implements CacheDependencyInterface
{
    public function __construct(
        private UriTagInterface $uriTag,
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

        // Accumulate across every embedded child: a resource that embeds more than one
        // child must depend on all of them, not only the last. Overwriting here used to
        // silently drop earlier children's dependencies (stale-cache bug).
        $from->headers[Header::SURROGATE_KEY] = isset($from->headers[Header::SURROGATE_KEY])
            ? sprintf('%s %s', $from->headers[Header::SURROGATE_KEY], $childTags)
            : $childTags;
    }
}
