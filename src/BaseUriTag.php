<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Override;
use ReflectionMethod;

use function method_exists;

/**
 * {@inheritDoc}
 *
 * Mirrors MatchQuery's view of onGet(): a parameter with a default is dropped from the base
 * query regardless of whether the request supplied it, and a parameter onGet() does not declare
 * is dropped too. `onGet(int $page = 1)` turns `?page=2` into the same base tag as no query at
 * all; `onGet(int $userId, int $page = 1)` keeps $userId, so users stay isolated while only the
 * page variants collapse.
 */
final class BaseUriTag implements BaseUriTagInterface
{
    public function __construct(private readonly UriTagInterface $uriTag = new UriTag())
    {
    }

    #[Override]
    public function __invoke(ResourceObject $ro): string
    {
        if (! method_exists($ro, 'onGet')) {
            return ($this->uriTag)($ro->uri);
        }

        $query = $ro->uri->query;
        $baseQuery = [];
        foreach ((new ReflectionMethod($ro, 'onGet'))->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable() || ! isset($query[$parameter->name])) {
                continue;
            }

            /** @psalm-suppress MixedAssignment */
            $baseQuery[$parameter->name] = $query[$parameter->name];
        }

        $baseUri = clone $ro->uri;
        $baseUri->query = $baseQuery;

        return ($this->uriTag)($baseUri);
    }
}
