<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\Resource\ResourceObject;
use Override;
use ReflectionMethod;

use function ltrim;
use function preg_replace;
use function sprintf;
use function strtolower;

/**
 * @internal
 *
 * Reads a required parameter from its name or the snake_case key BEAR.Resource also binds (`user_id` for `$userId`),
 * and returns it under the parameter name.
 */
final class MatchQuery implements MatchQueryInterface
{
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
            $key = isset($query[$parameter->name]) ? $parameter->name : self::snakeName($parameter->name);
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

    /** The conversion BEAR.Resource applies when it binds a query to a parameter */
    private static function snakeName(string $name): string
    {
        return ltrim(strtolower((string) preg_replace('/[A-Z]/', '_\0', $name)), '_');
    }
}
