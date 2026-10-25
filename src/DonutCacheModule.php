<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\RepositoryModule\Annotation\RefreshCache;
use BEAR\Resource\Annotation\Embed;
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
 * DonutHeaderSetter
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
        $this->bind(DonutHeaderSetter::class);
        $this->bind(CdnCacheControlHeaderSetterInterface::class)->to(CdnCacheControlHeaderSetter::class);
        $this->bind(DonutRepositoryInterface::class)->to(DonutRepository::class)->in(Scope::SINGLETON);
        // BC: the legacy flat logger interface is kept bound (deprecated). Internal cache code
        // now logs through SemanticLoggerInterface, so this instance receives no internal events.
        /** @psalm-suppress DeprecatedClass, DeprecatedInterface */
        $this->bind(RepositoryLoggerInterface::class)->to(RepositoryLogger::class)->in(Scope::SINGLETON);
        $this->bind(PurgerInterface::class)->to(NullPurger::class);
        $this->bind(UriTagInterface::class)->to(UriTag::class);
        // One registry per request (see DonutChildExecutions); issue #226
        $this->bind(DonutChildExecutionsInterface::class)->to(DonutChildExecutions::class)->in(Scope::SINGLETON);
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

        // Plain bindInterceptor, not bindPriorityInterceptor: this one must run INSIDE
        // EmbedInterceptor (see EmbedOnceInterceptor's own docblock for why Ray.Aop's
        // onion ordering guarantees that regardless of install order), where a priority
        // interceptor never would. Also requires #[Embed]: a class with none (Page\Index
        // is this shape) has nothing for EmbedOnceInterceptor to do, so it is left out of
        // the pointcut set entirely rather than woven in as a no-op - keeping both the
        // Ray.Aop-generated class name (and so EtagSetter's crc32 input) and the per-call
        // reflection cost unaffected for every page that embeds nothing. Still a
        // LogicalAndMatcher, not a bare annotatedWith(), so it stays in onionOrderMatch()'s
        // leftover (install-order-independent) bucket.
        $this->bindInterceptor(
            $this->matcher->logicalOr(
                $this->matcher->annotatedWith(CacheableResponse::class),
                $this->matcher->annotatedWith(DonutCache::class),
            ),
            $this->matcher->logicalAnd(
                $this->matcher->startsWith('onGet'),
                $this->matcher->annotatedWith(Embed::class),
            ),
            [EmbedOnceInterceptor::class],
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
        // Ray.Aop merges overlapping bindings without deduplicating, so a class whose own
        // declaration already governs the method is excluded. The two sets differ because
        // #[DonutCache] governs onGet only: excluding it from the write bindings too would
        // leave a #[RefreshCache] write on such a class with no interceptor at all.
        $readNotDeclared = $this->readNotDeclaredMatcher();
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

        // Same plain-bindInterceptor reasoning as installAopClassModule()'s own
        // EmbedOnceInterceptor binding (#[Embed] required too), for a class whose
        // #[CacheableResponse] sits on onGet() itself rather than on the class
        // (BlogPostingCache is this fixture).
        $this->bindInterceptor(
            $readNotDeclared,
            $this->matcher->logicalAnd(
                $this->matcher->annotatedWith(CacheableResponse::class),
                $this->matcher->logicalAnd(
                    $this->matcher->startsWith('onGet'),
                    $this->matcher->annotatedWith(Embed::class),
                ),
            ),
            [EmbedOnceInterceptor::class],
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

    /**
     * Classes governed by neither #[CacheableResponse], #[Cacheable] nor #[DonutCache] at
     * the class level - shared by installAopMethodModule()'s read bindings so the two stay
     * in lockstep: a method-level #[CacheableResponse] read is exactly where
     * EmbedOnceInterceptor must run too.
     */
    private function readNotDeclaredMatcher(): AbstractMatcher
    {
        return $this->matcher->logicalNot(
            $this->matcher->logicalOr(
                $this->matcher->annotatedWith(CacheableResponse::class),
                $this->matcher->logicalOr(
                    $this->matcher->annotatedWith(Cacheable::class),
                    $this->matcher->annotatedWith(DonutCache::class),
                ),
            ),
        );
    }
}
