<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

use function array_keys;
use function explode;
use function method_exists;
use function uri_template;

/**
 * The resources that the #[ResourceParam] declarations of a resource's onGet() read
 *
 * @internal
 */
final readonly class ResourceParamSources
{
    public function __construct(
        private ResourceInterface $resource,
        private ResourceStorageInterface $storage,
        private CacheTags $cacheTags,
        private CacheIdentityFactory $identityFactory,
    ) {
    }

    /** @return list<ResourceObject> each a new instance that has not run, carrying the headers of its stored entry when it has one */
    public function __invoke(ResourceObject $ro): array
    {
        $sources = [];
        foreach ($this->uris($ro) as $uri) {
            $source = $this->resource->newInstance($uri);
            $state = $this->storage->get($source->uri);
            if ($state instanceof ResourceState) {
                $source->headers = $state->headers;
            }

            $sources[] = $source;
        }

        return $sources;
    }

    /** For each of the resources, the tags a parent takes from an embedded child */
    public function tags(ResourceObject $ro): TagSet
    {
        $tags = new TagSet();
        foreach ($this($ro) as $source) {
            $tags = $tags->merge($this->cacheTags->tags($source));
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
        foreach (OnGetParameters::of($ro, $this->identityFactory)->bound() as $resourceParam) {
            $uri = $resourceParam->templated === true ? uri_template($resourceParam->uri, $ro->uri->query) : $resourceParam->uri;
            $uris[explode('#', $uri, 2)[0]] = true;
        }

        return array_keys($uris);
    }
}
