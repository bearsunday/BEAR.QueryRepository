<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheVersion;
use Ray\Di\AbstractModule;

class CacheVersionModule extends AbstractModule
{
    /**
     * @var string
     */
    private $version;

    /**
     * @param string              $cacheVersion
     * @param AbstractModule|null $module
     */
    public function __construct($cacheVersion, AbstractModule $module = null)
    {
        $this->version = $cacheVersion;
        parent::__construct($module);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind()->annotatedWith(CacheVersion::class)->toInstance($this->version);
    }
}
