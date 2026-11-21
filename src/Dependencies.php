<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

use function array_key_exists;

/**
 * What the response of one write depends on: the keys of the page and its holes, and what each embedded resource was found to declare
 *
 * @internal
 */
final class Dependencies
{
    /** @var array<string, TagSet|null> by class and URI; null when the resource declares nothing and passes nothing up */
    private array $found = [];

    /** @param BaseUriTag $groupTag the group tag a purge of a bare URI emits, whatever BaseUriTagInterface is bound to */
    public function __construct(
        private TagSet $keys,
        private readonly DependsOnTags $dependsOnTags,
        private readonly CacheTags $cacheTags,
        private readonly BaseUriTag $groupTag,
        private readonly CacheIdentityFactory $identityFactory,
    ) {
    }

    /** Adds the tags of a hole, including its group tag so that a purge of its bare URI reaches the page */
    public function hole(ResourceObject $ro): void
    {
        $this->embedded($ro);
        $own = new TagSet([$this->cacheTags->uriTag($ro->uri), ($this->groupTag)($ro)]);
        $this->keys = $this->keys->merge($own)->merge(TagSet::ofHeaders($ro->headers));
    }

    /**
     * Tags the embedded resource with what it declares in #[DependsOn], what its #[ResourceParam] read and what the
     * requests it embeds and ran pass up. What it declares is read once per URI: a page's material is read before the
     * page renders, while its body is as onGet() left it, and read again as the request of its own the page renders.
     * What the requests below pass up is read each time and kept with what was found before, as those requests may run
     * in between: a hole runs its own as the page renders.
     *
     * @return bool whether the page depends on it: it has a body, a declaration, an ETag or a Surrogate-Key
     */
    public function embedded(ResourceObject $ro): bool
    {
        $found = $this->hasRun($ro) ? $this->found($ro) : null;
        $found?->addTo($ro);

        return $ro->body !== null || $found !== null || isset($ro->headers[Header::ETAG]) || isset($ro->headers[Header::SURROGATE_KEY]);
    }

    public function addTo(ResourceObject $ro): void
    {
        $this->keys->addTo($ro);
    }

    private function found(ResourceObject $ro): TagSet|null
    {
        // The default tag keeps Stringable query values apart; (string) $ro->uri, or an application's tagger, may not
        $key = $ro::class . ' ' . $this->identityFactory->exactTag($ro->uri);
        if (! array_key_exists($key, $this->found)) {
            $declared = $this->dependsOnTags->declared($ro);
            $read = $this->dependsOnTags->sourceTags($ro)->merge($this->passedUp($ro));

            return $this->found[$key] = $read->isEmpty() ? $declared : ($declared ?? new TagSet())->merge($read);
        }

        $passedUp = $this->passedUp($ro);
        if (! $passedUp->isEmpty()) {
            $this->found[$key] = ($this->found[$key] ?? new TagSet())->merge($passedUp);
        }

        return $this->found[$key];
    }

    /** The tags of each request the resource embeds and ran that it depends on */
    private function passedUp(ResourceObject $ro): TagSet
    {
        $tags = new TagSet();
        foreach ($this->dependsOnTags->requests($ro) as $request) {
            if ($this->embedded($request->resourceObject)) {
                $tags = $tags->merge($this->cacheTags->tags($request->resourceObject));
            }
        }

        return $tags;
    }

    /** A request that has not run yet, such as a hole, still has the default code and no body */
    private function hasRun(ResourceObject $ro): bool
    {
        return $ro->code !== 200 || $ro->body !== null;
    }
}
