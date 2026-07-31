<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;
use Override;

use function gmdate;

final readonly class DevEtagSetter implements EtagSetterInterface
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    public function __invoke(ResourceObject $ro, int|null $time = null, HttpCache|null $httpCache = null)
    {
        $uriEtag = (new UriTag())($ro->uri);
        // Use URI as ETag in dev mode to understand how the cache is created.
        // This is useful for debugging purposes.
        // Usually, the ETag is a hash of the resource view or body.
        $ro->headers[Header::ETAG] = '"' . $uriEtag . '"';
        $ro->headers[Header::LAST_MODIFIED] = gmdate(Header::RFC7231, 0);
    }
}
