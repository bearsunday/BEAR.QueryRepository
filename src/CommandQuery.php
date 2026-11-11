<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use ReflectionMethod;

/** @internal */
final class CommandQuery
{
    /**
     * @param array<string, mixed> $getQuery
     *
     * @return array<string, mixed>
     */
    public static function baseQuery(ResourceObject $ro, array $getQuery): array
    {
        $baseQuery = [];
        foreach ((new ReflectionMethod($ro, 'onGet'))->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable() || ! isset($getQuery[$parameter->name])) {
                continue;
            }

            /** @psalm-suppress MixedAssignment */
            $baseQuery[$parameter->name] = $getQuery[$parameter->name];
        }

        return $baseQuery;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public static function snakeQuery(array $query): array
    {
        $snakeQuery = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($query as $key => $value) {
            $snakeQuery[BaseUriTag::snakeName($key)] = $value;
        }

        return $snakeQuery;
    }
}
