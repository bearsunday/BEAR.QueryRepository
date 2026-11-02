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
 * @internal
 *
 * Unlike MatchQuery (which keeps a parameter whenever the request supplied it, default or not),
 * this drops every default-valued onGet() parameter regardless of whether it was supplied, so
 * `onGet(int $page = 1)` turns `?page=2` into the same base tag as no query at all;
 * `onGet(int $userId, int $page = 1)` keeps $userId, so users stay isolated while only the page
 * variants collapse.
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
