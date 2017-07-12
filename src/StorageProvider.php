<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheVersion;
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
     * @var string
     */
    private $version;

    /**
     * @param CacheProvider $kvs
     * @param mixed         $appName
     * @param mixed         $version
     *
     * @Storage
     * @AppName("appName")
     * @CacheVersion("version")
     */
    public function __construct(CacheProvider $kvs, $appName, $version)
    {
        $this->kvs = $kvs;
        $this->appName = $appName;
        $this->version = (string) $version;
    }

    /**
     * {@inheritdoc}
     */
    public function get()
    {
        $this->kvs->setNamespace($this->appName . $this->version);

        return $this->kvs;
    }
}
