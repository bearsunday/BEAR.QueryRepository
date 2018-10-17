<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheVersion;
use Ray\Di\AbstractModule;

class CacheVersionModule extends AbstractModule
{
    /**
     * @var string
     */
    private $version;

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
