<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Override;
use ReflectionMethod;

use function ltrim;
use function method_exists;
use function preg_replace;
use function strtolower;

/** @internal */
final class BaseUriTag implements BaseUriTagInterface
{
    private CacheIdentityFactory|null $identityFactory = null;

    public function __construct(
        private readonly UriTagInterface $uriTag = new UriTag(),
        CacheIdentityFactory $identityFactory = new CacheIdentityFactory(),
    ) {
        $this->identityFactory = $identityFactory;
    }

    /** Defaulted parameters are excluded; required parameters retain their supplied query spelling. */
    #[Override]
    public function __invoke(ResourceObject $ro): string
    {
        if (! method_exists($ro, 'onGet')) {
            return $this->identityFactory()->groupTag(($this->uriTag)($ro->uri));
        }

        $query = $ro->uri->query;
        $baseQuery = [];
        foreach ((new ReflectionMethod($ro, 'onGet'))->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                continue;
            }

            $key = isset($query[$parameter->name]) ? $parameter->name : self::snakeName($parameter->name);
            if (! isset($query[$key])) {
                continue;
            }

            /** @psalm-suppress MixedAssignment */
            $baseQuery[$key] = $query[$key];
        }

        $baseUri = clone $ro->uri;
        $baseUri->query = $baseQuery;

        return $this->identityFactory()->groupTag(($this->uriTag)($baseUri));
    }

    private function identityFactory(): CacheIdentityFactory
    {
        return $this->identityFactory ?? new CacheIdentityFactory();
    }

    /** The conversion BEAR.Resource applies when it binds a query to a parameter */
    public static function snakeName(string $name): string
    {
        return ltrim(strtolower((string) preg_replace('/[A-Z]/', '_\0', $name)), '_');
    }
}
