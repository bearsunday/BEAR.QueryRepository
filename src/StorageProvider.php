<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Storage;
use BEAR\Resource\Annotation\AppName;
use Doctrine\Common\Cache\CacheProvider;
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
     * @param mixed         $appName
     *
     * @Storage
     * @AppName("appName")
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
