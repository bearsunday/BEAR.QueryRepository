<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\Resource\ResourceObject;
use Override;

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
        private readonly CacheIdentityFactory $identityFactory,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function __invoke(ResourceObject $ro): array
    {
        $getQuery = [];
        $query = $ro->uri->query;
        foreach (OnGetParameters::of($ro, $this->identityFactory)->all() as $parameter) {
            $key = $parameter->bindingKey($query);
            if ($key === null) {
                if ($parameter->hasDefault() || $parameter->isBound) {
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
