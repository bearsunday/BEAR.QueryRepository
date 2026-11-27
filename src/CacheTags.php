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
        private BaseUriTag $baseUriTag,
        private CacheIdentityFactory $identityFactory,
        #[AppSurrogateKey]
        private string|null $appKey = null,
    ) {
    }

    /** @param TagSet|null $declared null reads the Surrogate-Key header */
    public function tags(ResourceObject $ro, TagSet|null $declared = null): TagSet
    {
        return $this->own($ro)->merge($declared ?? TagSet::ofHeaders($ro->headers));
    }

    /** The URI tag, and the base tag that makes the entry reachable by a purge aimed at the bare URI (see BaseUriTag) */
    public function own(ResourceObject $ro): TagSet
    {
        return new TagSet([($this->uriTag)($ro->uri), ($this->baseUriTag)($ro)]);
    }

    /** Puts the application's key first in the resource's Surrogate-Key, once, so a CDN that ignores the keys past a length limit (Fastly: 16,384 bytes) still reads it */
    public function tagHeader(ResourceObject $ro): void
    {
        $tags = $this->ofEntry(new TagSet())->merge(TagSet::ofHeaders($ro->headers));
        if (! $tags->isEmpty()) {
            $ro->headers[Header::SURROGATE_KEY] = $tags->toHeader();
        }
    }

    /** The URI's own tag plus the group tag shared by its query variants (see BaseUriTag). */
    public function forUri(AbstractUri $uri): TagSet
    {
        $tag = ($this->uriTag)($uri);

        return new TagSet([$tag, $this->identityFactory->groupTag($tag)]);
    }

    /** Only the final steps add the application's key: storing an entry and {@see self::tagHeader()} */
    public function ofEntry(TagSet $tags): TagSet
    {
        return $this->appKey === null ? $tags : $tags->merge(new TagSet([$this->appKey]));
    }
}
