<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Override;

/** @internal */
final class BaseUriTag implements BaseUriTagInterface
{
    public function __construct(private readonly UriTagInterface $uriTag = new UriTag())
    {
    }

    #[Override]
    public function __invoke(ResourceObject $ro): string
    {
        return CacheIdentityFactory::groupTagFor($ro, $this->uriTag);
    }

    /** The conversion BEAR.Resource applies when it binds a query to a parameter */
    public static function snakeName(string $name): string
    {
        return CacheIdentityFactory::snakeName($name);
    }
}
