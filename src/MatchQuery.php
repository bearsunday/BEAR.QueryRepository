<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\Resource\ResourceObject;
use ReflectionMethod;

use function sprintf;

final class MatchQuery implements MatchQueryInterface
{
    /**
     * {@inheritDoc}
     */
    public function __invoke(ResourceObject $ro): array
    {
        $refParameters = (new ReflectionMethod($ro::class, 'onGet'))->getParameters();
        $getQuery = [];
        $query = $ro->uri->query;
        foreach ($refParameters as $parameter) {
            if (! isset($query[$parameter->name])) {
                throw new UnmatchedQuery(sprintf('%s %s', $ro->uri->method, (string) $ro->uri));
            }

            /** @psalm-suppress MixedAssignment */
            $getQuery[$parameter->name] = $query[$parameter->name];
        }

        return $getQuery;
    }
}
