<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;

use function explode;

/** @internal */
final readonly class CacheTags
{
    public function __construct(
        private UriTagInterface $uriTag,
        private BaseUriTagInterface $baseUriTag,
        private CacheIdentityFactory $identityFactory = new CacheIdentityFactory(),
    ) {
    }

    /**
     * @param list<string>|null $declaredTags
     *
     * @return list<string>
     */
    public function tags(ResourceObject $ro, array|null $declaredTags = null): array
    {
        // The base tag makes the entry reachable by a purge aimed at the bare URI (see BaseUriTag).
        $ownTags = [($this->uriTag)($ro->uri), ($this->baseUriTag)($ro)];
        $declaredTags ??= isset($ro->headers[Header::SURROGATE_KEY]) ? explode(' ', $ro->headers[Header::SURROGATE_KEY]) : [];

        return [...$ownTags, ...$declaredTags];
    }

    /**
     * The URI's own tag plus the group tag shared by its query variants (see BaseUriTag).
     *
     * @return list<string>
     */
    public function forUri(AbstractUri $uri): array
    {
        return [($this->uriTag)($uri), $this->identityFactory->groupTag(($this->uriTag)($uri))];
    }
}
