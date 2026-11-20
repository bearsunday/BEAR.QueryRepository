<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\DependsOnNotExpandable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\AbstractRequest;
use BEAR\Resource\ResourceObject;
use Closure;
use Generator;

use function array_keys;
use function is_array;
use function sprintf;

/**
 * Adds to a resource's Surrogate-Key the tag of each URI its #[DependsOn] declarations expand to, and for a child that
 * ran inside its parent, the tags of the resources its #[ResourceParam] declarations read
 *
 * @internal
 */
final readonly class DependsOnTags
{
    private CacheTags $cacheTags;

    public function __construct(
        private UriTagInterface $uriTag,
        private DependsOnRows $rows,
        private CacheIdentityFactory $identityFactory = new CacheIdentityFactory(),
        private ResourceParamSources|null $resourceParamSources = null,
        CacheTags|null $cacheTags = null,
    ) {
        $this->cacheTags = $cacheTags ?? new CacheTags($uriTag, new BaseUriTag($uriTag));
    }

    /**
     * Enumerate only immediate values of the array-cast body, in order and with duplicates.
     * Do not execute requests, descend into children, or consume Traversable bodies.
     *
     * @return Generator<AbstractRequest>
     */
    public function requests(ResourceObject $ro): Generator
    {
        /** @var mixed $body */
        foreach ((array) $ro->body as $body) {
            if ($body instanceof AbstractRequest) {
                yield $body;
            }
        }
    }

    /** Tags the resource being stored with its onGet() declarations and the row classes in its body */
    public function __invoke(ResourceObject $ro): void
    {
        $this->declared($ro)?->addTo($ro);
    }

    /** @return list<ResourceObject> the resources its #[ResourceParam] declarations read: {@see ResourceParamSources} */
    public function resourceParamSources(ResourceObject $ro): array
    {
        return $this->resourceParamSources === null ? [] : ($this->resourceParamSources)($ro);
    }

    /**
     * Tags a child that ran inside its parent with what ran() finds for it
     *
     * @return bool whether its parent depends on it, as tagEmbedded() decides
     */
    public function embedded(ResourceObject $child): bool
    {
        return $this->tagEmbedded($child, $this->hasRun($child) ? $this->ran($child) : null);
    }

    /**
     * @param TagSet|null $tags what ran() found for the child
     *
     * @return bool whether its parent depends on it: it has a body, a declaration, an ETag or a Surrogate-Key
     */
    public function tagEmbedded(ResourceObject $child, TagSet|null $tags): bool
    {
        $tags?->addTo($child);

        return $child->body !== null || $tags !== null || isset($child->headers[Header::ETAG]) || isset($child->headers[Header::SURROGATE_KEY]);
    }

    /**
     * Reads a resource that has run
     *
     * @param (Closure(ResourceObject): bool)|null $embedded reads a request it embeds; null reads it with embedded()
     *
     * @return TagSet|null what the resource declares and what its #[ResourceParam] read, with the tags of each request
     *                     it embeds and ran that it depends on; null when there is none
     */
    public function ran(ResourceObject $ro, Closure|null $embedded = null): TagSet|null
    {
        $declared = $this->declared($ro);
        $found = ($this->resourceParamSources?->tags($ro) ?? new TagSet())->merge($this->passedUp($ro, $embedded));

        return $found->isEmpty() ? $declared : ($declared ?? new TagSet())->merge($found);
    }

    /**
     * The tags of each request the resource embeds and ran that it depends on
     *
     * @param (Closure(ResourceObject): bool)|null $embedded reads a request it embeds; null reads it with embedded()
     */
    public function passedUp(ResourceObject $ro, Closure|null $embedded = null): TagSet
    {
        $embedded ??= $this->embedded(...);
        $tags = new TagSet();
        foreach ($this->requests($ro) as $body) {
            if ($embedded($body->resourceObject)) {
                $tags = $tags->merge($this->cacheTags->tags($body->resourceObject));
            }
        }

        return $tags;
    }

    /** A request that has not run yet, such as a hole, still has the default code and no body */
    public function hasRun(ResourceObject $ro): bool
    {
        return $ro->code !== 200 || $ro->body !== null;
    }

    /** @return TagSet|null null when neither onGet() nor a row class in the body declares */
    private function declared(ResourceObject $ro): TagSet|null
    {
        $onClass = DependsOnTemplates::ofClass($ro::class);
        if ($onClass !== []) {
            throw new DependsOnNotExpandable((string) $ro->uri, DependsOnTemplates::declaration($onClass[0]), 'declared on a resource class');
        }

        $onGet = DependsOnTemplates::ofOnGet($ro);
        $tags = $onGet === [] ? null : new TagSet($this->fromResult($ro, $onGet));
        $rows = RowClassWalk::tags($ro->body, $this->uriTag, (string) $ro->uri);

        return $rows === null ? $tags : ($tags ?? new TagSet())->merge(new TagSet($rows));
    }

    /**
     * Each declaration expands with the rows it reads and the arguments, which are not on the write path, so they are rebuilt from
     * the request query and the defaults: {@see DependsOnExpansion::tags()}
     *
     * @param list<DependsOn> $declarations
     *
     * @return list<string>
     */
    private function fromResult(ResourceObject $ro, array $declarations): array
    {
        $onGet = OnGetParameters::of($ro, $this->identityFactory);
        $query = $ro->uri->query;
        $arguments = [];
        foreach ($onGet->all() as $parameter) {
            // #[ResourceParam] fills it from another resource whatever the query holds, so its value cannot be read back
            if ($parameter->isBound) {
                continue;
            }

            $key = $parameter->presentKey($query);
            if ($key !== null || $parameter->hasDefault()) {
                /** @psalm-suppress MixedAssignment */
                $arguments[$parameter->name] = $key !== null ? $query[$key] : $parameter->default();
            }
        }

        $source = sprintf('%s onGet()', (string) $ro->uri);
        $expansion = new DependsOnExpansion($this->uriTag, $source, is_array($ro->body) ? $ro->body : [], $arguments, $onGet->names(), array_keys($onGet->bound()));
        $tags = [];
        foreach ($declarations as $dependsOn) {
            $tags = [...$tags, ...$expansion->tags($dependsOn, $this->rows->of($ro, $dependsOn->key))];
        }

        return $tags;
    }
}
