<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\HttpCacheInterface as DeprecatedHttpCacheInterface;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;

use function http_response_code;

/**
 * @psalm-suppress DeprecatedInterface for BC
 */
final class HttpCache implements HttpCacheInterface, DeprecatedHttpCacheInterface
{
    /** @var ResourceStorageInterface */
    private $storage;

    public function __construct(ResourceStorageInterface $storage)
    {
        $this->storage = $storage;
    }

    /**
     * {@inheritdoc}
     */
    public function isNotModified(array $server): bool
    {
        return isset($server['HTTP_IF_NONE_MATCH']) && $this->storage->hasEtag($server['HTTP_IF_NONE_MATCH']);
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function transfer()
    {
        http_response_code(304);
    }
}
