<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\RepositoryModule\Annotation\RefreshCache;
use Override;
use Ray\Aop\AbstractMatcher;
use Ray\Aop\MatcherInterface;
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
        // BC: the legacy flat logger interface is kept bound (deprecated). Internal cache code
        // now logs through SemanticLoggerInterface, so this instance receives no internal events.
        /** @psalm-suppress DeprecatedClass, DeprecatedInterface */
        $this->bind(RepositoryLoggerInterface::class)->to(RepositoryLogger::class)->in(Scope::SINGLETON);
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
            self::commandMethods($this->matcher),
            [DonutCommandInterceptor::class],
        );
    }

    /**
     * onPost writes too: a POST that changes state must refresh the donut it invalidates
     *
     * @see \BEAR\QueryRepository\CacheableModule::installAopModule() same set on the value-cache side
     */
    private static function commandMethods(MatcherInterface $matcher): AbstractMatcher
    {
        return $matcher->logicalOr(
            $matcher->startsWith('onPut'),
            $matcher->logicalOr(
                $matcher->startsWith('onPost'),
                $matcher->logicalOr(
                    $matcher->startsWith('onPatch'),
                    $matcher->startsWith('onDelete'),
                ),
            ),
        );
    }

    private function installAopMethodModule(): void
    {
        $this->bindInterceptor(
            $this->matcher->any(),
            $this->matcher->logicalAnd(
                $this->matcher->annotatedWith(CacheableResponse::class),
                $this->matcher->startsWith('onGet'),
            ),
            [DonutCacheInterceptor::class],
        );

        // A write is not a query: the donut interceptor answers from the store, so binding it to
        // a command method makes the write return the cached representation without running.
        $this->bindInterceptor(
            $this->matcher->any(),
            $this->matcher->logicalAnd(
                $this->matcher->annotatedWith(CacheableResponse::class),
                self::commandMethods($this->matcher),
            ),
            [DonutCommandInterceptor::class],
        );
        $this->bindInterceptor(
            $this->matcher->any(),
            $this->matcher->annotatedWith(RefreshCache::class),
            [DonutCommandInterceptor::class],
        );
    }
}
