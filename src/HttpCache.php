<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
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
        if (! isset($server['HTTP_IF_NONE_MATCH'])) {
            return false;
        }

        return $this->storage->hasEtag($server['HTTP_IF_NONE_MATCH']);
    }

    /**
     * {@inheritdoc}
     */
    public function transfer()
    {
        if (PHP_SAPI === 'cli') {
            echo '304 Not Modified' . PHP_EOL . PHP_EOL;

            return;
        }
        \http_response_code(304);
    }
}
