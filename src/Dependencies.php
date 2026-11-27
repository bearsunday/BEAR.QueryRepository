<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Closure;

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

    public function __construct(
        private TagSet $keys,
        private readonly DependsOnTags $dependsOnTags,
        private readonly ResourceParamSources $resourceParamSources,
        private readonly CacheTags $cacheTags,
        private readonly CacheIdentityFactory $identityFactory,
    ) {
    }

    /** Adds the tags of a hole, including its group tag so that a purge of its bare URI reaches the page */
    public function hole(ResourceObject $ro): void
    {
        $this->embedded($ro);
        $this->keys = $this->keys->merge($this->cacheTags->own($ro))->merge(TagSet::ofHeaders($ro->headers));
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
        return $this->tag($ro, $this->hasRun($ro) ? $this->found($ro) : null);
    }

    /**
     * Tags the embedded resource with what its own result declares, read each time: two requests for one URI may return
     * different bodies
     *
     * @return bool whether the parent depends on it, as {@see self::embedded()}
     */
    public function read(ResourceObject $ro): bool
    {
        return $this->tag($ro, $this->hasRun($ro) ? $this->ofResult($ro, $this->read(...)) : null);
    }

    public function addTo(ResourceObject $ro): void
    {
        $this->keys->addTo($ro);
    }

    private function tag(ResourceObject $ro, TagSet|null $found): bool
    {
        $found?->addTo($ro);

        return $ro->body !== null || $found !== null || isset($ro->headers[Header::ETAG]) || isset($ro->headers[Header::SURROGATE_KEY]);
    }

    private function found(ResourceObject $ro): TagSet|null
    {
        // The default tag keeps Stringable query values apart; (string) $ro->uri, or an application's tagger, may not
        $key = $ro::class . ' ' . $this->identityFactory->exactTag($ro->uri);
        if (! array_key_exists($key, $this->found)) {
            return $this->found[$key] = $this->ofResult($ro, $this->embedded(...));
        }

        $passedUp = $this->passedUp($ro, $this->embedded(...));
        if (! $passedUp->isEmpty()) {
            $this->found[$key] = ($this->found[$key] ?? new TagSet())->merge($passedUp);
        }

        return $this->found[$key];
    }

    /**
     * What the resource declares, what its #[ResourceParam] read and what the requests it embeds pass up; null when none
     *
     * @param Closure(ResourceObject): bool $embedded reads each request it embeds
     */
    private function ofResult(ResourceObject $ro, Closure $embedded): TagSet|null
    {
        $declared = $this->dependsOnTags->declared($ro);
        $read = $this->resourceParamSources->tags($ro)->merge($this->passedUp($ro, $embedded));

        return $read->isEmpty() ? $declared : ($declared ?? new TagSet())->merge($read);
    }

    /**
     * The tags of each request the resource embeds and ran that it depends on
     *
     * @param Closure(ResourceObject): bool $embedded
     */
    private function passedUp(ResourceObject $ro, Closure $embedded): TagSet
    {
        $tags = new TagSet();
        foreach ($this->dependsOnTags->requests($ro) as $request) {
            if ($embedded($request->resourceObject)) {
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
