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
    private CacheIdentityFactory|null $identityFactory = null;

    public function __construct(CacheIdentityFactory $identityFactory = new CacheIdentityFactory())
    {
        $this->identityFactory = $identityFactory;
    }

    private function identityFactory(): CacheIdentityFactory
    {
        return $this->identityFactory ?? new CacheIdentityFactory();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function __invoke(AbstractUri $uri): string
    {
        return $this->identityFactory()->exactTag($uri);
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
