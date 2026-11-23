<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;

/** @internal */
final readonly class CommandQuery
{
    public function __construct(
        private CacheIdentityFactory $identityFactory,
    ) {
    }

    /** The resource's URI without its query, which holds the values the write was sent */
    public function withoutQuery(ResourceObject $ro): string
    {
        return "{$ro->uri->scheme}://{$ro->uri->host}{$ro->uri->path}";
    }

    /**
     * The URIs whose tags are the resource's own key: its onGet() query without the default-valued parameters, as
     * BaseUriTag drops them, and its snake_case spelling, which a GET stored under the snake_case key keeps in its
     * group tag
     *
     * @param array<string, mixed> $getQuery what MatchQueryInterface read from the resource's URI
     *
     * @return list<AbstractUri>
     */
    public function ownUris(ResourceObject $ro, array $getQuery): array
    {
        $delUri = clone $ro->uri;
        $baseQuery = $this->baseQuery($ro, $getQuery);
        $delUri->query = $baseQuery;
        $snakeQuery = $this->snakeQuery($baseQuery);
        if ($snakeQuery === $baseQuery) {
            return [$delUri];
        }

        $snakeUri = clone $ro->uri;
        $snakeUri->query = $snakeQuery;

        return [$delUri, $snakeUri];
    }

    /**
     * @param array<string, mixed> $getQuery
     *
     * @return array<string, mixed>
     */
    public function baseQuery(ResourceObject $ro, array $getQuery): array
    {
        $baseQuery = [];
        foreach (OnGetParameters::of($ro, $this->identityFactory)->required() as $parameter) {
            if (! isset($getQuery[$parameter->name])) {
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
    public function snakeQuery(array $query): array
    {
        $snakeQuery = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($query as $key => $value) {
            $snakeQuery[$this->identityFactory->snakeName($key)] = $value;
        }

        return $snakeQuery;
    }
}
