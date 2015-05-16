<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\VoidCache;
use Ray\Di\Di\Named;

final class HttpCache implements HttpCacheInterface
{
    const ETAG_KEY = 'etag:';

    /**
     * @var Cache
     */
    private $kvs;

    /**
     * @param Cache $kvs
     *
     * @Named("appName=BEAR\Resource\Annotation\AppName,kvs=BEAR\RepositoryModule\Annotation\Storage")
     */
    public function __construct($appName, Cache $kvs = null)
    {
        $this->kvs = $kvs ?: new VoidCache;
    }

    /**
     * {@inheritdoc}
     */
    public function isNotModified(array $server)
    {
        if (! isset($server['REQUEST_METHOD']) ||
            ! $server['REQUEST_METHOD'] === 'GET' ||
            ! isset($server['HTTP_IF_NONE_MATCH'])
        ) {
            return false;
        }
        $etagKey = self::ETAG_KEY . $server['HTTP_IF_NONE_MATCH'];

        return $this->kvs->contains($etagKey) ? true : false;
    }

    /**
     * Invoke http cache (304)
     */
    public function __invoke(array $server)
    {
        if ($this->isNotModified($server)) {
            http_response_code(304);
            exit(0);
        }
    }
}
