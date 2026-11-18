<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\Resource\ResourceObject;
use Override;
use ReflectionMethod;

use function sprintf;

/**
 * @internal
 *
 * Reads each onGet() parameter from its name or, failing that, the snake_case key BEAR.Resource also binds
 * (`user_id` for `$userId`), and returns it under the parameter name. A parameter #[ResourceParam] binds is not the
 * caller's to supply, so it is read only when the query holds it.
 */
final class MatchQuery implements MatchQueryInterface
{
    public function __construct(
        private readonly CacheIdentityFactory $identityFactory = new CacheIdentityFactory(),
    ) {
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function __invoke(ResourceObject $ro): array
    {
        $onGet = new ReflectionMethod($ro::class, 'onGet');
        $bound = ResourceParamSources::declared($onGet);
        $refParameters = $onGet->getParameters();
        $getQuery = [];
        $query = $ro->uri->query;
        foreach ($refParameters as $parameter) {
            $key = isset($query[$parameter->name]) ? $parameter->name : $this->identityFactory->snakeName($parameter->name);
            if (! isset($query[$key])) {
                if ($parameter->isDefaultValueAvailable() || isset($bound[$parameter->name])) {
                    continue;
                }

                // The parameter named, not the query quoted: a write's query holds the values it was sent
                throw new UnmatchedQuery(sprintf("%s %s://%s%s has no '%s'", $ro->uri->method, $ro->uri->scheme, $ro->uri->host, $ro->uri->path, $parameter->name));
            }

            /** @psalm-suppress MixedAssignment */
            $getQuery[$parameter->name] = $query[$key];
        }

        return $getQuery;
    }
}
