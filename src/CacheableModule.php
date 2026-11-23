<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\AbstractCacheControl;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\Commands;
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
 *  -Commands
 *  RefreshInterceptor
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
        $this->bind()->annotatedWith(Commands::class)->toProvider(CommandsProvider::class);
        $this->bind(RefreshInterceptor::class);
        $this->bind(RefreshTemplateValidator::class)->in(Scope::SINGLETON);
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
            $this->matcher->annotatedWith(Cacheable::class),
            new UnsafeMethodMatcher(),
            [CommandInterceptor::class],
        );
        $this->bindInterceptor(
            $this->matcher->logicalNot(
                $this->matcher->annotatedWith(Cacheable::class),
            ),
            new CommandMethodMatcher(),
            [RefreshInterceptor::class],
        );
        // A write with neither attribute invalidates its own key too, unless the class stores its GET, or the method's
        // #[CacheableResponse] or #[RefreshCache] does
        $this->bindInterceptor(
            $this->matcher->logicalAnd(
                $this->matcher->subclassesOf(ResourceObject::class),
                $this->matcher->logicalNot(new CacheableClassMatcher()),
            ),
            new UndeclaredWriteMatcher(),
            [RefreshInterceptor::class],
        );

        $this->bindInterceptor(
            $this->matcher->annotatedWith(AbstractCacheControl::class),
            $this->matcher->startsWith('onGet'),
            [HttpCacheInterceptor::class],
        );
    }
}
