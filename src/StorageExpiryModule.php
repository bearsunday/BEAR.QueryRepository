<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

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

    public function __construct(int $short, int $medium, int $long, AbstractModule $module = null)
    {
        $this->short = $short;
        $this->medium = $medium;
        $this->long = $long;
        parent::__construct($module);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind(Expiry::class)->toInstance(new Expiry($this->short, $this->medium, $this->long));
    }
}
