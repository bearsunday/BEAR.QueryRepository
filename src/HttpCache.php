<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\HttpCacheInterface as DeprecatedHttpCacheInterface;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;
use Override;

use function http_response_code;

/** @psalm-suppress DeprecatedInterface for BC */
final readonly class HttpCache implements HttpCacheInterface, DeprecatedHttpCacheInterface
{
    public function __construct(
        private ResourceStorageInterface $storage,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function isNotModified(array $server): bool
    {
        return isset($server[Header::HTTP_IF_NONE_MATCH]) && $this->storage->hasEtag($server[Header::HTTP_IF_NONE_MATCH]);
    }

    /**
     * {@inheritDoc}
     *
     * @return void
     */
    #[Override]
    public function transfer()
    {
        // @codeCoverageIgnoreStart
        http_response_code(304);
        // @codeCoverageIgnoreEnd
    }
}
