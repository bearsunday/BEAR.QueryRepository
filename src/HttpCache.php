<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Storage;
use Doctrine\Common\Cache\Cache;

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
     * @Storage
     */
    public function __construct(Cache $kvs)
    {
        $this->kvs = $kvs;
    }

    /**
     * {@inheritdoc}
     */
    public function isNotModified(array $server)
    {
        if (isset($server['REQUEST_METHOD'])
            && $server['REQUEST_METHOD'] === 'GET'
            && isset($server['HTTP_IF_NONE_MATCH'])
        ) {
            $etagKey = self::ETAG_KEY . $server['HTTP_IF_NONE_MATCH'];

            return $this->kvs->contains($etagKey) ? true : false;
        }

        return false;
    }
}
