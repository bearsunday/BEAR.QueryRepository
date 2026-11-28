<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\AbstractCacheControl;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;
use BEAR\Sunday\Provide\Transfer\ConditionalResponse;
use BEAR\Sunday\Provide\Transfer\ConditionalResponseInterface;
use Override;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

/**
 *  Provides HttpCacheInterface and derived bindings
 *
 *  The following bindings are provided:
 *
 *  HttpCacheInterface
 *  DeclaredCacheControl
 *  RefreshTemplateValidator
 *  StorageExpiryModule
 */
final class CacheableModule extends AbstractModule
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function configure(): void
    {
        $this->bind(HttpCacheInterface::class)->to(HttpCache::class);
        $this->bind(ConditionalRequest::class);
        $this->bind(ConditionalResponseInterface::class)->to(ConditionalResponse::class);
        $this->bind(DeclaredCacheControl::class)->in(Scope::SINGLETON);
        $this->bind(UriTemplates::class);
        $this->bind(RefreshTemplateValidator::class)->in(Scope::SINGLETON);
        $this->bind(CacheableClassMatcher::class);
        $this->install(new StorageExpiryModule(60, 60 * 60, 60 * 60 * 24));
        $this->installAopModule();
    }

    protected function installAopModule(): void
    {
        $this->bindPriorityInterceptor(
            $this->matcher->annotatedWith(Cacheable::class),
            $this->matcher->startsWith('onGet'),
            [CacheInterceptor::class],
        );
        $this->bindInterceptor(
            $this->matcher->subclassesOf(ResourceObject::class),
            new UnsafeMethodMatcher(),
            [CommandInterceptor::class],
        );
        $this->bindInterceptor(
            $this->matcher->annotatedWith(AbstractCacheControl::class),
            $this->matcher->startsWith('onGet'),
            [HttpCacheInterceptor::class],
        );
    }
}
