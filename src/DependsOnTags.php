<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

use function array_keys;
use function is_array;
use function sprintf;

/**
 * The tag of each URI a resource's #[DependsOn] declarations expand to
 *
 * @internal
 */
final readonly class DependsOnTags
{
    public function __construct(
        private UriTagInterface $uriTag,
        private DependsOnRows $rows,
        private CacheIdentityFactory $identityFactory,
        private DependsOnTemplates $templates,
        private UriTemplates $uriTemplates,
    ) {
    }

    /** @return TagSet|null what onGet() declares; null when it declares nothing */
    public function declared(ResourceObject $ro): TagSet|null
    {
        $onGet = $this->templates->ofOnGet($ro);

        return $onGet === [] ? null : new TagSet($this->fromResult($ro, $onGet));
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
        $expansion = new DependsOnExpansion($this->uriTag, $this->uriTemplates, $source, is_array($ro->body) ? $ro->body : [], $arguments, $onGet->names(), array_keys($onGet->bound()));
        $tags = [];
        foreach ($declarations as $dependsOn) {
            $tags = [...$tags, ...$expansion->tags($dependsOn, $this->rows->of($ro, $dependsOn->key))];
        }

        return $tags;
    }
}
