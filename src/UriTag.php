<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\Uri;
use Override;

use function implode;
use function uri_template;

final class UriTag implements UriTagInterface
{
    public function __construct(private readonly CacheIdentityFactory $identityFactory = new CacheIdentityFactory())
    {
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function __invoke(AbstractUri $uri): string
    {
        return $this->identityFactory->exactTag($uri);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function fromAssoc(string $uriTemplate, array $assoc): string
    {
        $surrogateKeys = [];
        foreach ($assoc as $item) {
            $surrogateKeys[] = ($this)(new Uri(uri_template($uriTemplate, $item)));
        }

        return implode(' ', $surrogateKeys);
    }
}
