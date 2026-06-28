<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\SafeSemanticLoggerProvider;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\RepositoryModule\Annotation\RefreshCache;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

/**
 * Provides ResourceStorageInterface and derived bindings
 *
 * The following bindings are provided:
 *
 * HeaderSetter
 * CdnCacheControlHeaderSetterInterface
 * DonutRepositoryInterface
 * PurgerInterface
 * UriTagInterface
 *
 * The following interceptors are provided:
 *
 * DonutCacheableResponseInterceptor
 * DonutCacheInterceptor
 * DonutCommandInterceptor
 * DonutCacheInterceptor
 */
final class DonutCacheModule extends AbstractModule
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function configure(): void
    {
        $this->bind(HeaderSetter::class);
        $this->bind(CdnCacheControlHeaderSetterInterface::class)->to(CdnCacheControlHeaderSetter::class);
        $this->bind(DonutRepositoryInterface::class)->to(DonutRepository::class)->in(Scope::SINGLETON);
        // Shared semantic logging session: open() at an interceptor and event() at storage
        // resolve to the same SafeSemanticLogger singleton (see SafeSemanticLoggerProvider).
        $this->bind(SemanticLoggerInterface::class)->toProvider(SafeSemanticLoggerProvider::class)->in(Scope::SINGLETON);
        $this->bind(PurgerInterface::class)->to(NullPurger::class);
        $this->bind(UriTagInterface::class)->to(UriTag::class);
        $this->installAopClassModule();
        $this->installAopMethodModule();
    }

    private function installAopClassModule(): void
    {
        $this->bind(DonutRepository::class)->in(Scope::SINGLETON);
        $this->bind(DonutRendererInterface::class)->to(DonutRenderer::class);
        $this->bindPriorityInterceptor(
            $this->matcher->annotatedWith(CacheableResponse::class),
            $this->matcher->startsWith('onGet'),
            [DonutCacheableResponseInterceptor::class],
        );
        $this->bindPriorityInterceptor(
            $this->matcher->annotatedWith(DonutCache::class),
            $this->matcher->startsWith('onGet'),
            [DonutCacheInterceptor::class],
        );

        $this->bindInterceptor(
            $this->matcher->annotatedWith(CacheableResponse::class),
            $this->matcher->logicalOr(
                $this->matcher->startsWith('onPut'),
                $this->matcher->logicalOr(
                    $this->matcher->startsWith('onPatch'),
                    $this->matcher->startsWith('onDelete'),
                ),
            ),
            [DonutCommandInterceptor::class],
        );
    }

    private function installAopMethodModule(): void
    {
        $this->bindInterceptor(
            $this->matcher->any(),
            $this->matcher->annotatedWith(CacheableResponse::class),
            [DonutCacheInterceptor::class],
        );
        $this->bindInterceptor(
            $this->matcher->any(),
            $this->matcher->annotatedWith(RefreshCache::class),
            [DonutCacheInterceptor::class],
        );
    }
}
