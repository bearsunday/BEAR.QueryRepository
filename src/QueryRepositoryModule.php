<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace BEAR\QueryRepository;

use BEAR\Resource\NamedParameter;
use BEAR\Resource\NamedParameterInterface;
use BEAR\RepositoryModule\Annotation\QueryRepository as QueryRepositoryAnnotation;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\CacheProvider;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

class QueryRepositoryModule extends AbstractModule
{
    /**
     * @var AbstractModule
     */
    private $appName;

    /**
     * @param AbstractModule $appName
     */
    public function __construct($appName)
    {
        $this->appName = $appName;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind(QueryRepositoryInterface::class)->to(QueryRepository::class)->in(Scope::SINGLETON);
        $this->bind(Cache::class)->annotatedWith('resource_repository')->toProvider(StorageProvider::class);
        $this->bind(CacheProvider::class)->annotatedWith('resource_repository')->to(ArrayCache::class)->in(Scope::SINGLETON);
        $this->bind()->annotatedWith('app_name')->toInstance($this->appName);
        $this->bind(SetEtagInterface::class)->to(SetEtag::class)->in(Scope::SINGLETON);
        $this->bind(NamedParameterInterface::class)->to(NamedParameter::class)->in(Scope::SINGLETON);
        $this->bind(Reader::class)->to(AnnotationReader::class)->in(Scope::SINGLETON);
        $this->bindInterceptor(
            $this->matcher->annotatedWith(QueryRepositoryAnnotation::class),
            $this->matcher->startsWith('onGet'),
            [QueryInterceptor::class]
        );
        foreach (['onPost', 'onPut', 'onPatch', 'onDelete'] as $starts) {
            $this->bindInterceptor(
                $this->matcher->annotatedWith(QueryRepositoryAnnotation::class),
                $this->matcher->startsWith($starts),
                [CommandInterceptor::class]
            );
        }
    }
}
