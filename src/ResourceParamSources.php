<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Annotation\ResourceParam;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use ReflectionMethod;

use function array_keys;
use function explode;
use function method_exists;
use function uri_template;

/**
 * The stored resources that the #[ResourceParam] declarations of a resource's onGet() read
 *
 * @internal
 */
final readonly class ResourceParamSources
{
    public function __construct(
        private ResourceInterface $resource,
        private ResourceStorageInterface $storage,
        private CacheTags $cacheTags,
    ) {
    }

    /** @return list<ResourceObject> each a new instance that has not run, carrying the headers of its stored entry; one with no stored entry is left out */
    public function __invoke(ResourceObject $ro): array
    {
        $sources = [];
        foreach ($this->uris($ro) as $uri) {
            $source = $this->resource->newInstance($uri);
            $state = $this->storage->get($source->uri);
            if (! $state instanceof ResourceState) {
                continue;
            }

            $source->headers = $state->headers;
            $sources[] = $source;
        }

        return $sources;
    }

    /** @return list<string> for each of the resources, the tags a parent takes from an embedded child */
    public function tags(ResourceObject $ro): array
    {
        $tags = [];
        foreach ($this($ro) as $source) {
            $tags = [...$tags, ...$this->cacheTags->tags($source)];
        }

        return $tags;
    }

    /**
     * Built as BEAR\Resource\AssistedResourceParam builds the URI it requests, less the fragment, which names the field it reads
     *
     * @return list<string>
     */
    private function uris(ResourceObject $ro): array
    {
        if (! method_exists($ro, 'onGet')) {
            return [];
        }

        $uris = [];
        foreach (self::declared(new ReflectionMethod($ro, 'onGet')) as $resourceParam) {
            $uri = $resourceParam->templated === true ? uri_template($resourceParam->uri, $ro->uri->query) : $resourceParam->uri;
            $uris[explode('#', $uri, 2)[0]] = true;
        }

        return array_keys($uris);
    }

    /**
     * As BEAR\Resource\NamedParamMetas binds: only the onGet() that runs counts, and a declaration on the method takes
     * the parameter it names from one on the parameter
     *
     * @return array<string, ResourceParam> by parameter name
     */
    public static function declared(ReflectionMethod $onGet): array
    {
        $declared = [];
        foreach ($onGet->getAttributes(ResourceParam::class) as $attribute) {
            $resourceParam = $attribute->newInstance();
            $declared[$resourceParam->param] = $resourceParam;
        }

        foreach ($onGet->getParameters() as $parameter) {
            $attribute = $parameter->getAttributes(ResourceParam::class)[0] ?? null;
            if ($attribute !== null && ! isset($declared[$parameter->name])) {
                $declared[$parameter->name] = $attribute->newInstance();
            }
        }

        return $declared;
    }
}
