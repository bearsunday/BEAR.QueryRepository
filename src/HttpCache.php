<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
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
    public $responder;

    /**
     * @var string
     */
    private $appName;

    /**
     * @var array
     */
    private $server;

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
     * @param array  $server  $_SERVER
     */
    public function __construct($appName, array $server)
    {
        $this->appName = $appName;
        $this->server = $server;
        $this->kvs = apc_fetch($this->appName . '-kvs');
        if (! $this->kvs) {
            $prodModule = $this->appName . '\Module\ProdModule';
            $this->kvs = (new Injector(new $prodModule))->getInstance(Cache::class, Storage::class);
            apc_store($this->appName . '-kvs', $this->kvs);
        }
        $this->responder = new HttpCacheSaver($this->kvs);
    }

    public function isNotModified()
    {
        if (! isset($this->server['HTTP_IF_NONE_MATCH']) || ! $this->kvs->contains('etag-id:' . stripslashes($this->server['HTTP_IF_NONE_MATCH']))) {
            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    public function hasContents()
    {
        if (! isset($this->server['REQUEST_URI'])) {
            return false;
        }
        $requestUri = 'request-uri:' . $this->server['REQUEST_URI'];
        $this->requestUri = $this->kvs->fetch($requestUri);

        return $this->requestUri ? true : false;
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
     * @return bool is flushed contents ?
     */
    public function __invoke()
    {
        if ($this->isNotModified()) {
            http_response_code(304);

            return true;
        }
        if ($this->hasContents()) {
            $this->transfer(new HttpCacheResponder);

            return true;
        }

        return false;
    }
}
