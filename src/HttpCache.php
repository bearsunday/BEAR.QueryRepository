<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

final class HttpCache implements HttpCacheInterface
{
    /**
     * @var ResourceStorageInterface
     */
    private $storage;

    public function __construct(ResourceStorageInterface $storage)
    {
        $this->storage = $storage;
    }

    /**
     * {@inheritdoc}
     */
    public function isNotModified(array $server) : bool
    {
        return isset($server['HTTP_IF_NONE_MATCH']) && $this->storage->hasEtag($server['HTTP_IF_NONE_MATCH']);
    }

    /**
     * {@inheritdoc}
     */
    public function transfer()
    {
        \http_response_code(304);
    }
}
