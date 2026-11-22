<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Annotation\ResourceParam;
use BEAR\Resource\ResourceObject;
use ReflectionException;
use ReflectionMethod;

use function array_map;

/**
 * The parameters of a resource's onGet()
 *
 * @internal
 */
final readonly class OnGetParameters
{
    /**
     * @param list<OnGetParameter>         $parameters
     * @param array<string, ResourceParam> $bound
     */
    private function __construct(
        private array $parameters,
        private array $bound,
    ) {
    }

    /**
     * #[ResourceParam] is read as BEAR\Resource\NamedParamMetas binds: only the onGet() that runs counts, and a
     * declaration on the method takes the parameter it names from one on the parameter
     *
     * @throws ReflectionException When the resource has no onGet().
     */
    public static function of(ResourceObject $ro, CacheIdentityFactory $identityFactory): self
    {
        $onGet = new ReflectionMethod($ro, 'onGet');
        $bound = [];
        foreach ($onGet->getAttributes(ResourceParam::class) as $attribute) {
            $resourceParam = $attribute->newInstance();
            $bound[$resourceParam->param] = $resourceParam;
        }

        $parameters = [];
        foreach ($onGet->getParameters() as $parameter) {
            $attribute = $parameter->getAttributes(ResourceParam::class)[0] ?? null;
            if ($attribute !== null && ! isset($bound[$parameter->name])) {
                $bound[$parameter->name] = $attribute->newInstance();
            }

            $parameters[] = new OnGetParameter($parameter, $identityFactory->snakeName($parameter->name), isset($bound[$parameter->name]));
        }

        return new self($parameters, $bound);
    }

    /** @return list<OnGetParameter> */
    public function all(): array
    {
        return $this->parameters;
    }

    /** @return list<OnGetParameter> those without a default value */
    public function required(): array
    {
        $required = [];
        foreach ($this->parameters as $parameter) {
            if (! $parameter->hasDefault()) {
                $required[] = $parameter;
            }
        }

        return $required;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (OnGetParameter $parameter): string => $parameter->name, $this->parameters);
    }

    /** @return array<string, ResourceParam> the #[ResourceParam] declarations, by the parameter each names */
    public function bound(): array
    {
        return $this->bound;
    }
}
