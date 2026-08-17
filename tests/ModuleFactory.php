<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\SafeSemanticLogger;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\Module\ResourceModule;
use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Ray\Di\AbstractModule;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ModuleFactory
{
    /**
     * A test module with recording turned on
     *
     * The package default is NullSemanticLogger: nothing is recorded until an app installs a
     * log module. Tests assert on the log, so they bind the recording logger without a sink -
     * a sink flushes at shutdown, while a test flushes when it wants to read.
     */
    public static function getInstance(string $namespace): QueryRepositoryModule
    {
        $module = new QueryRepositoryModule(new ResourceModule($namespace));
        $module->override(new class extends AbstractModule{
            protected function configure(): void
            {
                $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->to(ArrayAdapter::class);
                $this->bind(AdapterInterface::class)->annotatedWith(EtagPool::class)->to(ArrayAdapter::class);
                $this->bind(SemanticLoggerInterface::class)->annotatedWith(CacheLog::class)->toInstance(new SafeSemanticLogger(new SemanticLogger()));
            }
        });

        return $module;
    }
}
