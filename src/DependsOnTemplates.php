<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;
use ReflectionClass;

use function array_values;
use function serialize;

/**
 * The #[DependsOn] declarations on a resource's onGet(), cached per class
 *
 * @internal
 */
final class DependsOnTemplates
{
    /** @var array<class-string, list<DependsOn>> */
    private array $onGet = [];

    /** @return list<DependsOn> the declarations on onGet(), including an onGet() the class overrides, each URI and key once */
    public function ofOnGet(ResourceObject $ro): array
    {
        if (isset($this->onGet[$ro::class])) {
            return $this->onGet[$ro::class];
        }

        $declarations = [];
        for ($ref = new ReflectionClass($ro); $ref !== false && $ref->hasMethod('onGet'); $ref = $ref->getParentClass()) {
            foreach ($ref->getMethod('onGet')->getAttributes(DependsOn::class) as $attribute) {
                $dependsOn = $attribute->newInstance();
                $declarations[serialize([$dependsOn->uri, $dependsOn->key])] ??= $dependsOn;
            }
        }

        return $this->onGet[$ro::class] = array_values($declarations);
    }
}
