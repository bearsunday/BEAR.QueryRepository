<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\QueryRepository as QueryRepositoryAnnotation;
use BEAR\RepositoryModule\Annotation\Storage;
use BEAR\Resource\NamedParameter;
use BEAR\Resource\NamedParameterInterface;
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
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind(QueryRepositoryInterface::class)->to(QueryRepository::class)->in(Scope::SINGLETON);
        $this->bind(Cache::class)->annotatedWith(Storage::class)->toProvider(StorageProvider::class)->in(Scope::SINGLETON);
        $this->bind(CacheProvider::class)->annotatedWith(Storage::class)->to(ArrayCache::class)->in(Scope::SINGLETON);
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
