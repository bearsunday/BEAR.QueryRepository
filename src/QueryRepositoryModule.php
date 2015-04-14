<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\ExpiryConfig;
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
     * @var array
     */
    private $expiry = [
        'short' => 60,          // 1 min
        'medium' => 60 * 60,    // 1 hour
        'long' => 60 * 60 * 24, // 1 day
        'never' => 0
    ];

//    public function __construct(array $expiry = [
//        'short' => 60,          // 1 min
//        'medium' => 60 * 60,    // 1 hour
//        'long' => 60 * 60 * 24, // 1 day
//        'never' => 0
//    ], AbstractModule $module = null)
//    {
//        $this->expiry = $expiry;
//        parent::__construct($module);
//    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind()->annotatedWith(ExpiryConfig::class)->toInstance($this->expiry);
        $this->bind(QueryRepositoryInterface::class)->to(QueryRepository::class)->in(Scope::SINGLETON);
        $this->bind(Cache::class)->annotatedWith(Storage::class)->toProvider(StorageProvider::class)->in(Scope::SINGLETON);
        $this->bind(CacheProvider::class)->annotatedWith(Storage::class)->to(ArrayCache::class)->in(Scope::SINGLETON);
        $this->bind(SetEtagInterface::class)->to(SetEtag::class)->in(Scope::SINGLETON);
        $this->bind(NamedParameterInterface::class)->to(NamedParameter::class)->in(Scope::SINGLETON);
        $this->bind(Reader::class)->to(AnnotationReader::class)->in(Scope::SINGLETON);
        // @Cacheable
        $this->bindInterceptor(
            $this->matcher->annotatedWith(Cacheable::class),
            $this->matcher->startsWith('onGet'),
            [CacheInterceptor::class]
        );
        foreach (['onPost', 'onPut', 'onPatch', 'onDelete'] as $starts) {
            $this->bindInterceptor(
                $this->matcher->annotatedWith(Cacheable::class),
                $this->matcher->startsWith($starts),
                [CommandInterceptor::class]
            );
        }
        // @QueryRepository
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
