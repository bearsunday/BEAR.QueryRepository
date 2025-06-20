<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\Request;
use BEAR\Resource\ResourceObject;
use DateTimeInterface;
use Override;

use function gmdate;

final class DevEtagSetter implements EtagSetterInterface
{
    public function __construct(
        private readonly CacheDependencyInterface $cacheDeperency,
    ) {
    }

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
        $ro->headers[Header::ETAG] = $uriEtag;
        $ro->headers[Header::LAST_MODIFIED] = gmdate(DateTimeInterface::RFC7231, 0);
        $this->setCacheDependency($ro);
    }

    /** @codeCoverageIgnore */
    private function setCacheDependency(ResourceObject $ro): void
    {
        /** @var mixed $body */
        foreach ((array) $ro->body as $body) {
            if ($body instanceof Request && isset($body->resourceObject->headers[Header::ETAG])) {
                $this->cacheDeperency->depends($ro, $body->resourceObject);
            }
        }
    }
}
