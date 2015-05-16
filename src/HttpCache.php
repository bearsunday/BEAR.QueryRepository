<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Storage;
use Doctrine\Common\Cache\Cache;
use Ray\Di\Injector;

final class HttpCache
{
    const ETAG_KEY = 'etag:';

    /**
     * @var string
     */
    private $appName;

    /**
     * @var Cache
     */
    private $kvs;

    /**
     * @var mixed
     */
    private $cache;

    /**
     * @param string $appName application name (Vendor\Package)
     */
    public function __construct($appName, Cache $kvs = null)
    {
        $this->appName = $appName;
        $this->kvs = $kvs ?: $this->getKvs();
    }

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
     * Invoke http cache (304 and uri cache)
     *
     * @return array [$httpCode, $message]
     */
    public function __invoke(array $server)
    {
        if ($this->isNotModified($server)) {
            http_response_code(304);

            return [304, "etag:{$server['HTTP_IF_NONE_MATCH']}"];
        }
    }

    private function getKvs()
    {
        $kvs = apc_fetch($this->appName . '-kvs');
        if (! $kvs) {
            $prodModule = $this->appName . '\Module\ProdModule';
            if (!class_exists($prodModule)) {
                $prodModule = '\BEAR\Package\Context\ProdModule';
            }
            $kvs = (new Injector(new $prodModule))->getInstance(Cache::class, Storage::class);
            apc_store($this->appName . '-kvs', $this->kvs);
        }

        return $kvs;
    }
}
