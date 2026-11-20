<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\AppSurrogateKey;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;

/** @internal */
final readonly class CacheTags
{
    /** @param string|null $appKey null unless AppSurrogateKeyModule binds one */
    public function __construct(
        private UriTagInterface $uriTag,
        private BaseUriTagInterface $baseUriTag,
        private CacheIdentityFactory $identityFactory = new CacheIdentityFactory(),
        #[AppSurrogateKey]
        private string|null $appKey = null,
    ) {
    }

    /** @param TagSet|null $declared null reads the Surrogate-Key header */
    public function tags(ResourceObject $ro, TagSet|null $declared = null): TagSet
    {
        // The base tag makes the entry reachable by a purge aimed at the bare URI (see BaseUriTag).
        $own = new TagSet([($this->uriTag)($ro->uri), ($this->baseUriTag)($ro)]);

        return $this->withAppKey($own->merge($declared ?? TagSet::ofHeaders($ro->headers)));
    }

    /** Adds the application's key to the resource's Surrogate-Key, once */
    public function tagHeader(ResourceObject $ro): void
    {
        $this->withAppKey(new TagSet())->addTo($ro);
    }

    public function uriTag(AbstractUri $uri): string
    {
        return ($this->uriTag)($uri);
    }

    /** The URI's own tag plus the group tag shared by its query variants (see BaseUriTag). */
    public function forUri(AbstractUri $uri): TagSet
    {
        $tag = ($this->uriTag)($uri);

        return new TagSet([$tag, $this->identityFactory->groupTag($tag)]);
    }

    private function withAppKey(TagSet $tags): TagSet
    {
        return $this->appKey === null ? $tags : $tags->merge(new TagSet([$this->appKey]));
    }
}
