<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\AppSurrogateKey;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;

use function explode;
use function implode;
use function in_array;

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

        return $this->withAppKey([...$ownTags, ...$declaredTags]);
    }

    /** Adds the application's key to the resource's Surrogate-Key, once */
    public function tagHeader(ResourceObject $ro): void
    {
        $tags = isset($ro->headers[Header::SURROGATE_KEY]) ? explode(' ', $ro->headers[Header::SURROGATE_KEY]) : [];
        $withKey = $this->withAppKey($tags);
        if ($withKey !== $tags) {
            $ro->headers[Header::SURROGATE_KEY] = implode(' ', $withKey);
        }
    }

    public function uriTag(AbstractUri $uri): string
    {
        return ($this->uriTag)($uri);
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

    /**
     * @param list<string> $tags
     *
     * @return list<string>
     */
    private function withAppKey(array $tags): array
    {
        if ($this->appKey === null || in_array($this->appKey, $tags, true)) {
            return $tags;
        }

        return [...$tags, $this->appKey];
    }
}
