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
 * (`user_id` for `$userId`), and returns it under the parameter name.
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
        $refParameters = (new ReflectionMethod($ro::class, 'onGet'))->getParameters();
        $getQuery = [];
        $query = $ro->uri->query;
        foreach ($refParameters as $parameter) {
            $key = isset($query[$parameter->name]) ? $parameter->name : $this->identityFactory->snakeName($parameter->name);
            if (! isset($query[$key])) {
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }

                throw new UnmatchedQuery(sprintf('%s %s', $ro->uri->method, (string) $ro->uri));
            }

            /** @psalm-suppress MixedAssignment */
            $getQuery[$parameter->name] = $query[$key];
        }

        return $getQuery;
    }
}
