<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\RepositoryModule\Annotation\RefreshCache;
use Override;
use Ray\Aop\AbstractMatcher;
use Ray\Aop\MatcherInterface;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

final class DonutCacheModule extends AbstractModule
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function configure(): void
    {
        $this->bind(HeaderSetter::class);
        $this->bind(DonutHeaderSetter::class);
        $this->bind(CdnCacheControlHeaderSetterInterface::class)->to(CdnCacheControlHeaderSetter::class);
        $this->bind(DonutRepositoryInterface::class)->to(DonutRepository::class)->in(Scope::SINGLETON);
        // Kept bound for BC; internal cache code logs through SemanticLoggerInterface instead,
        // so an injected RepositoryLoggerInterface instance never receives these events.
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
     * @see \BEAR\QueryRepository\CacheableModule::installAopModule()
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
        // Ray.Aop merges without deduplicating, so a class whose own declaration governs a
        // method is excluded here - read and write sets differ since #[DonutCache] covers onGet only.
        $readNotDeclared = $this->matcher->logicalNot(
            $this->matcher->logicalOr(
                $this->matcher->annotatedWith(CacheableResponse::class),
                $this->matcher->logicalOr(
                    $this->matcher->annotatedWith(Cacheable::class),
                    $this->matcher->annotatedWith(DonutCache::class),
                ),
            ),
        );
        $writeNotDeclared = $this->matcher->logicalNot(
            $this->matcher->logicalOr(
                $this->matcher->annotatedWith(CacheableResponse::class),
                $this->matcher->annotatedWith(Cacheable::class),
            ),
        );
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
                self::commandMethods($this->matcher),
            ),
            [DonutCommandInterceptor::class],
        );
        $this->bindInterceptor(
            $writeNotDeclared,
            $this->matcher->annotatedWith(RefreshCache::class),
            [DonutCommandInterceptor::class],
        );
    }
}
