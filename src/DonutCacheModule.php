<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\RepositoryModule\Annotation\RefreshCache;
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
 * RepositoryLoggerInterface
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
        // Kept bound for BC; internal cache code logs through SemanticLoggerInterface instead,
        // so an injected RepositoryLoggerInterface instance never receives these events.
        /** @psalm-suppress DeprecatedClass, DeprecatedInterface */
        $this->bind(RepositoryLoggerInterface::class)->to(RepositoryLogger::class)->in(Scope::SINGLETON);
        $this->bind(PurgerInterface::class)->to(NullPurger::class);
        $this->bind(UriTagInterface::class)->to(UriTag::class);
        $this->bind(CacheIdentityFactory::class);
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
            new UnsafeMethodMatcher(),
            [DonutCommandInterceptor::class],
        );
    }

    private function installAopMethodModule(): void
    {
        // Ray.Aop merges without deduplicating, so a class whose own declaration governs a
        // method is excluded; unifying the sets would leave a #[RefreshCache] write with no
        // interceptor.
        $readNotDeclared = $this->matcher->logicalNot(
            $this->matcher->logicalOr(
                new CacheableClassMatcher(),
                $this->matcher->annotatedWith(DonutCache::class),
            ),
        );
        $writeNotDeclared = $this->matcher->logicalNot(new CacheableClassMatcher());
        $this->bindInterceptor(
            $readNotDeclared,
            $this->matcher->logicalAnd(
                $this->matcher->annotatedWith(CacheableResponse::class),
                $this->matcher->startsWith('onGet'),
            ),
            [DonutCacheInterceptor::class],
        );

        $this->bindInterceptor(
            $writeNotDeclared,
            $this->matcher->logicalAnd(
                $this->matcher->annotatedWith(CacheableResponse::class),
                new UnsafeMethodMatcher(),
            ),
            [DonutCommandInterceptor::class],
        );
        // Not an annotation matcher alone, which is woven in attribute order, outside RefreshInterceptor: in binding
        // order, the query is matched before a #[Purge] beside #[RefreshCache] runs, as on a #[CacheableResponse] class
        $this->bindInterceptor(
            $writeNotDeclared,
            $this->matcher->logicalAnd($this->matcher->annotatedWith(RefreshCache::class), $this->matcher->any()),
            [DonutCommandInterceptor::class],
        );
    }
}
