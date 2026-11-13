<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\DependsOnNotExpandable;
use BEAR\Resource\AbstractRequest;
use BEAR\Resource\ResourceObject;
use Closure;
use Generator;
use ReflectionMethod;

use function array_key_exists;
use function array_keys;
use function explode;
use function in_array;
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
        $this->addTags($ro, $this->declared($ro) ?? []);
    }

    /** @return list<ResourceObject> the resources its #[ResourceParam] declarations read: {@see ResourceParamSources} */
    public function resourceParamSources(ResourceObject $ro): array
    {
        return $this->resourceParamSources === null ? [] : ($this->resourceParamSources)($ro);
    }

    /**
     * Tags a child that ran inside its parent with what ran() finds for it
     *
     * @return bool whether its parent depends on it: a declaration was found, or it carries an ETag or a Surrogate-Key
     */
    public function embedded(ResourceObject $child): bool
    {
        return $this->tagEmbedded($child, $this->hasRun($child) ? $this->ran($child) : null);
    }

    /**
     * @param list<string>|null $tags what ran() found for the child
     *
     * @return bool whether its parent depends on it: a declaration was found, or it carries an ETag or a Surrogate-Key
     */
    public function tagEmbedded(ResourceObject $child, array|null $tags): bool
    {
        $this->addTags($child, $tags ?? []);

        return $tags !== null || isset($child->headers[Header::ETAG]) || isset($child->headers[Header::SURROGATE_KEY]);
    }

    /**
     * Reads a resource that has run
     *
     * @param (Closure(ResourceObject): bool)|null $embedded reads a request it embeds; null reads it with embedded()
     *
     * @return list<string>|null what the resource declares and what its #[ResourceParam] read, with the tags of each
     *                           request it embeds and ran that it depends on; null when there is none
     */
    public function ran(ResourceObject $ro, Closure|null $embedded = null): array|null
    {
        $tags = $this->declared($ro);
        $read = $this->resourceParamSources?->tags($ro) ?? [];
        if ($read !== []) {
            $tags = [...$tags ?? [], ...$read];
        }

        $passedUp = $this->passedUp($ro, $embedded);

        return $passedUp === [] ? $tags : [...$tags ?? [], ...$passedUp];
    }

    /**
     * The tags of each request the resource embeds and ran that it depends on
     *
     * @param (Closure(ResourceObject): bool)|null $embedded reads a request it embeds; null reads it with embedded()
     *
     * @return list<string>
     */
    public function passedUp(ResourceObject $ro, Closure|null $embedded = null): array
    {
        $embedded ??= $this->embedded(...);
        $tags = [];
        foreach ($this->requests($ro) as $body) {
            if ($embedded($body->resourceObject)) {
                $tags = [...$tags, ...$this->cacheTags->tags($body->resourceObject)];
            }
        }

        return $tags;
    }

    /** A request that has not run yet, such as a hole, still has the default code and no body */
    public function hasRun(ResourceObject $ro): bool
    {
        return $ro->code !== 200 || $ro->body !== null;
    }

    /** @return list<string>|null null when neither onGet() nor a row class in the body declares */
    private function declared(ResourceObject $ro): array|null
    {
        $onClass = DependsOnTemplates::ofClass($ro::class);
        if ($onClass !== []) {
            throw new DependsOnNotExpandable((string) $ro->uri, DependsOnTemplates::declaration($onClass[0]), 'declared on a resource class');
        }

        $onGet = DependsOnTemplates::ofOnGet($ro);
        $tags = $onGet === [] ? null : $this->fromArguments($ro, $onGet);
        $rows = RowClassWalk::tags($ro->body, $this->uriTag, (string) $ro->uri);
        if ($rows === null) {
            return $tags;
        }

        return [...$tags ?? [], ...$rows];
    }

    /**
     * The call's arguments are not on the write path, so they are rebuilt from the request query and the defaults
     *
     * @param list<string> $templates
     *
     * @return list<string>
     */
    private function fromArguments(ResourceObject $ro, array $templates): array
    {
        $source = sprintf('%s onGet()', (string) $ro->uri);
        $onGet = new ReflectionMethod($ro, 'onGet');
        // #[ResourceParam] fills these from another resource whatever the query holds, so their value cannot be read back
        $bound = array_keys(ResourceParamSources::declared($onGet));
        $parameters = [];
        $arguments = [];
        foreach ($onGet->getParameters() as $parameter) {
            $parameters[] = $parameter->name;
            $arguments += $this->argument($parameter->name, $ro->uri->query);
            if (! array_key_exists($parameter->name, $arguments) && $parameter->isDefaultValueAvailable()) {
                /** @psalm-suppress MixedAssignment */
                $arguments[$parameter->name] = $parameter->getDefaultValue();
            }
        }

        $tags = [];
        foreach ($templates as $template) {
            foreach (DependsOnTemplates::variables($template) as $variable) {
                if (! in_array($variable, $parameters, true)) {
                    throw new DependsOnNotExpandable($source, DependsOnTemplates::declaration($template), sprintf("'%s' is not an onGet() parameter", $variable));
                }

                if (in_array($variable, $bound, true)) {
                    throw new DependsOnNotExpandable($source, DependsOnTemplates::declaration($template), sprintf("'%s' is bound by #[ResourceParam]", $variable));
                }
            }

            $tag = DependsOnTemplates::tag($this->uriTag, $template, $arguments, $source);
            if ($tag !== null) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed> the value under the parameter's name or its snake_case form; empty when the query has neither
     */
    private function argument(string $name, array $query): array
    {
        foreach ([$name, $this->identityFactory->snakeName($name)] as $key) {
            if (array_key_exists($key, $query)) {
                return [$name => $query[$key]];
            }
        }

        return [];
    }

    /** @param list<string> $tags */
    private function addTags(ResourceObject $ro, array $tags): void
    {
        if ($tags === []) {
            return;
        }

        $existing = isset($ro->headers[Header::SURROGATE_KEY]) ? explode(' ', $ro->headers[Header::SURROGATE_KEY]) : [];
        $ro->headers[Header::SURROGATE_KEY] = (new TagSet([...$existing, ...$tags]))->toHeader();
    }
}
