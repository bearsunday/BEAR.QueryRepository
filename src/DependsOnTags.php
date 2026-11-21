<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\DependsOnNotExpandable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\AbstractRequest;
use BEAR\Resource\ResourceObject;
use Generator;

use function array_keys;
use function is_array;
use function sprintf;

/**
 * The tag of each URI a resource's #[DependsOn] declarations expand to, and the resources its #[ResourceParam] declarations read
 *
 * @internal
 */
final readonly class DependsOnTags
{
    public function __construct(
        private UriTagInterface $uriTag,
        private DependsOnRows $rows,
        private CacheIdentityFactory $identityFactory,
        private ResourceParamSources $resourceParamSources,
    ) {
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

    /** @return list<ResourceObject> the resources its #[ResourceParam] declarations read: {@see ResourceParamSources} */
    public function resourceParamSources(ResourceObject $ro): array
    {
        return ($this->resourceParamSources)($ro);
    }

    /** For each resource its #[ResourceParam] declarations read, the tags a parent takes from an embedded child */
    public function sourceTags(ResourceObject $ro): TagSet
    {
        return $this->resourceParamSources->tags($ro);
    }

    /** @return TagSet|null what onGet() and the row classes in the body declare; null when neither declares */
    public function declared(ResourceObject $ro): TagSet|null
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
