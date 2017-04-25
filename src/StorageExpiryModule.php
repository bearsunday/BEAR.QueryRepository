<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\ExpiryConfig;
use Ray\Di\AbstractModule;

class StorageExpiryModule extends AbstractModule
{
    /**
     * @var int
     */
    private $short;

    /**
     * @var int
     */
    private $medium;

    /**
     * @var int
     */
    private $long;

    /**
     * @param int            $short
     * @param int            $medium
     * @param int            $long
     * @param AbstractModule $module
     */
    public function __construct($short, $medium, $long, AbstractModule $module = null)
    {
        $this->short = $short;
        $this->medium = $module;
        $this->long = $long;
        parent::__construct($module);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind()->annotatedWith(ExpiryConfig::class)->toInstance(new Expiry($this->short, $this->medium, $this->long));
    }
}
