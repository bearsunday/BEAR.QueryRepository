<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Storage;
use Doctrine\Common\Cache\CacheProvider;

final class HttpCache implements HttpCacheInterface
{
    const ETAG_KEY = 'etag:';

    /**
     * @var CacheProvider
     */
    private $kvs;

    /**
     * @param CacheProvider $kvs
     *
     * @Storage
     */
    public function __construct(CacheProvider $kvs)
    {
        $this->kvs = $kvs;
    }

    /**
     * {@inheritdoc}
     */
    public function isNotModified(array $server) : bool
    {
        if (! isset($server['HTTP_IF_NONE_MATCH'])) {
            return false;
        }
        $etagKey = self::ETAG_KEY . $server['HTTP_IF_NONE_MATCH'];

        return $this->kvs->contains($etagKey) ? true : false;
    }
}
