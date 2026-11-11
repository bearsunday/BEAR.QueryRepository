<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Override;
use ReflectionMethod;

use function method_exists;

/** @internal */
final readonly class BaseUriTag implements BaseUriTagInterface
{
    public function __construct(
        private UriTagInterface $uriTag = new UriTag(),
        private CacheIdentityFactory $identityFactory = new CacheIdentityFactory(),
    ) {
    }

    /** Defaulted parameters are excluded; required parameters retain their supplied query spelling. */
    #[Override]
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
