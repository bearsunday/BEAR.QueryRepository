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
    /**
     * @var HttpCacheSaver
     */
    public $saver;

    /**
     * @var string
     */
    private $appName;

    /**
     * @var Cache
     */
    private $kvs;

    /**
     * @var string
     */
    private $requestUri;

    /**
     * @param string $appName application name (Vendor\Package)
     */
    public function __construct($appName)
    {
        error_log($appName);
        $this->appName = $appName;
        $this->kvs = apc_fetch($this->appName . '-kvs');
        if (! $this->kvs) {
            $prodModule = $this->appName . '\Module\ProdModule';
            if (! class_exists($prodModule)) {
                $prodModule = '\BEAR\Package\Context\ProdModule';
            }
            $this->kvs = (new Injector(new $prodModule))->getInstance(Cache::class, Storage::class);
            apc_store($this->appName . '-kvs', $this->kvs);
        }
        $this->saver = new HttpCacheSaver($this->kvs);
    }

    public function isNotModified(array $server)
    {
        if (! isset($server['HTTP_IF_NONE_MATCH'])) {
            return false;
        }
        $etagKey = 'request-uri-etag:' . $server['REQUEST_URI'] . $server['HTTP_IF_NONE_MATCH'];

        return $this->kvs->contains($etagKey) ? true : false;
    }
    
    /**
     * Transfer cached contents
     *
     * @param HttpCacheResponder $responder
     */
    public function transfer(HttpCacheResponder $responder)
    {
        list($headers, $view) = $this->kvs->fetch($this->requestUri);
        $responder($headers, $view);
    }

    /**
     * Invoke http cache (304 and uri cache)
     *
     * @return array [$httpCode, $message]
     */
    public function __invoke(array $server)
    {
        if (! isset($_SERVER['REQUEST_METHOD'])) {
            return [0, 'not http request'];
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return [0, "method:{$_SERVER['REQUEST_METHOD']}"];
        }
        if ($this->isNotModified($server)) {
            http_response_code(304);

            return [304, "etag:{$server['HTTP_IF_NONE_MATCH']}"];
        }

        return [0, "no-hit:{$server['REQUEST_URI']}"];
    }
}
