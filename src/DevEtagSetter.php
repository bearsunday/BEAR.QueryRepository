<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\Request;
use BEAR\Resource\ResourceObject;
use DateTimeInterface;

use function gmdate;
use function http_build_query;
use function sprintf;
use function str_replace;

final class DevEtagSetter implements EtagSetterInterface
{
    public function __construct(
        private CacheDependencyInterface $cacheDeperency,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(ResourceObject $ro, int|null $time = null, HttpCache|null $httpCache = null)
    {
        $ro->headers[Header::ETAG] =  sprintf('%s_%s', str_replace([':', '/'], ['_', '_'], $ro->uri->path), http_build_query($ro->uri->query));
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
