<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use ReflectionMethod;

use function method_exists;

/**
 * The group tag of a GET resource: its URI tag without the default-valued onGet() parameters
 *
 * A query-variant cache entry carries it besides its own tag, and a purge of the bare URI targets it.
 *
 * @internal
 */
final readonly class BaseUriTag
{
    public function __construct(
        private UriTagInterface $uriTag = new UriTag(),
        private CacheIdentityFactory $identityFactory = new CacheIdentityFactory(),
    ) {
    }

    /** Defaulted parameters are excluded; required parameters retain their supplied query spelling. Without onGet(), the group tag is derived from the full URI. */
    public function __invoke(ResourceObject $ro): string
    {
        if (! method_exists($ro, 'onGet')) {
            return $this->identityFactory->groupTag(($this->uriTag)($ro->uri));
        }

        $query = $ro->uri->query;
        $baseQuery = [];
        foreach ((new ReflectionMethod($ro, 'onGet'))->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                continue;
            }

            $key = isset($query[$parameter->name]) ? $parameter->name : $this->identityFactory->snakeName($parameter->name);
            if (! isset($query[$key])) {
                continue;
            }

            /** @psalm-suppress MixedAssignment */
            $baseQuery[$key] = $query[$key];
        }

        $baseUri = clone $ro->uri;
        $baseUri->query = $baseQuery;

        return $this->identityFactory->groupTag(($this->uriTag)($baseUri));
    }
}
