<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace BEAR\QueryRepository;

use Doctrine\Common\Cache\CacheProvider;
use Ray\Di\Di\Named;
use Ray\Di\ProviderInterface;

class StorageProvider implements ProviderInterface
{
    /**
     * @var CacheProvider
     */
    private $kvs;

    /**
     * @var string
     */
    private $appName;

    /**
     * @param CacheProvider $kvs
     * @param string        $appName
     *
     * @Named("kvs=resource_repository, appName=app_name")
     */
    public function __construct(CacheProvider $kvs, $appName)
    {
        $this->kvs = $kvs;
        $this->appName = $appName;
    }

    /**
     * {@inheritdoc}
     */
    public function get()
    {
        $this->kvs->setNamespace($this->appName);

        return $this->kvs;
    }

}
