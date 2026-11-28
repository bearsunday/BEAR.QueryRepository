<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\CacheStoreException;
use BEAR\QueryRepository\Exception\ReturnValueIsNotResourceObjectException;
use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\RefreshCache;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\FactoryInterface;
use BEAR\Resource\InvokerInterface;
use BEAR\Resource\Request;
use BEAR\Resource\ResourceObject;
use Override;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;
use ReflectionClass;

use function method_exists;

/**
 * After a write that answers below 400: purges the resource's own key, every URI its #[Purge]/#[Refresh] name and each
 * #[Link(rel: 'collection')] of its onGet(), then reads its own URI again where the write stores it, then each #[Refresh] URI.
 * A purge that fails with CacheStoreException does not stop the rest: the first failure is thrown once every read has run, or
 * an exception from one of those reads in its place.
 *
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#tag-based-cache-invalidation
 * @internal
 */
final readonly class CommandInterceptor implements MethodInterceptor
{
    public function __construct(
        private RefreshAnnotatedCommand $command,
        private CommandQuery $commandQuery,
        private MatchQueryInterface $matchQuery,
        private QueryRepositoryInterface $repository,
        private FactoryInterface $factory,
        private InvokerInterface $invoker,
        private RefreshTemplateValidator $refreshTemplateValidator,
        private CommandScope $scope,
        private CacheableClassMatcher $cacheableClass,
    ) {
    }

    #[Override]
    public function invoke(MethodInvocation $invocation): ResourceObject
    {
        ($this->refreshTemplateValidator)($invocation);
        // The key is the URI the write was sent to, whatever the handler does to its own or returns; the return gives
        // the code
        $target = $invocation->getThis();
        $sent = $target instanceof ResourceObject ? $this->sent($target) : null;
        /** @psalm-suppress MixedAssignment */
        $ro = $invocation->proceed();
        if (! $ro instanceof ResourceObject) {
            throw new ReturnValueIsNotResourceObjectException($invocation->getThis()::class);
        }

        $this->scope->run($invocation, $ro, function () use ($invocation, $ro, $sent): void {
            $this->purgeAndRefresh($invocation, $ro, $sent);
        });

        return $ro;
    }

    /** @param MethodInvocation<object> $invocation */
    private function purgeAndRefresh(MethodInvocation $invocation, ResourceObject $ro, ResourceObject|null $sent): void
    {
        $stored = $this->isStored($invocation);
        $getQuery = $sent === null ? null : $this->getQuery($invocation, $sent, $stored);
        $targets = $this->command->declared($invocation, $ro);
        $own = $sent === null || $getQuery === null ? [] : $this->commandQuery->ownUris($sent, $getQuery);
        $failure = $this->purge([...$own, ...$targets->purge]);
        if ($stored && $sent !== null && $getQuery !== null) {
            $this->rebuild($invocation, $sent, $getQuery);
        }

        $this->command->refresh($targets->refresh);

        if ($failure !== null) {
            throw $failure;
        }
    }

    /** The resource the write was sent to, cloned with its URI before the handler runs; none without a URI or an onGet() */
    private function sent(ResourceObject $target): ResourceObject|null
    {
        /** @var AbstractUri|null $uri a resource object taken from the injector has none */
        $uri = $target->uri;

        return $uri === null || ! method_exists($target, 'onGet') ? null : clone $target;
    }

    /**
     * Whether the write's own GET is stored, so the write reads it again: a #[Cacheable] or #[CacheableResponse] class,
     * or a method marked #[CacheableResponse] or #[RefreshCache]
     *
     * @param MethodInvocation<object> $invocation
     */
    private function isStored(MethodInvocation $invocation): bool
    {
        $method = $invocation->getMethod();

        return $this->cacheableClass->matchesClass(new ReflectionClass($invocation->getThis()), [])
            || $method->getAnnotation(CacheableResponse::class) !== null
            || $method->getAnnotation(RefreshCache::class) !== null;
    }

    /**
     * The onGet() query of the resource's own key; null where the write's query cannot address it
     *
     * @param MethodInvocation<object> $invocation
     *
     * @return array<string, mixed>|null
     */
    private function getQuery(MethodInvocation $invocation, ResourceObject $sent, bool $stored): array|null
    {
        try {
            return ($this->matchQuery)($sent);
        } catch (UnmatchedQuery $e) {
            $this->scope->skipUnmatched($invocation, $sent, $e, $stored);

            return null;
        }
    }

    /**
     * Read to rebuild the cache entry; with the exact $getQuery, default-valued parameters included
     *
     * @param MethodInvocation<object> $invocation
     * @param array<string, mixed>     $getQuery
     */
    private function rebuild(MethodInvocation $invocation, ResourceObject $sent, array $getQuery): void
    {
        try {
            $get = $this->factory->newInstance($this->commandQuery->withoutQuery($sent));
            (new Request($this->invoker, $get))($getQuery);
        } catch (UnmatchedQuery $e) {
            // Thrown by a write the rebuilding GET made
            $this->scope->skipUnmatched($invocation, $sent, $e, true);
        }
    }

    /**
     * Each URI once, compared as the string purge() is given: its own key and a #[Purge] of itself are one purge
     *
     * @param list<AbstractUri> $uris
     *
     * @return CacheStoreException|null the first failure, such as a CDN purge that failed after the local pool was invalidated
     */
    private function purge(array $uris): CacheStoreException|null
    {
        $purged = [];
        $failure = null;
        foreach ($uris as $uri) {
            $key = (string) $uri;
            if (isset($purged[$key])) {
                continue;
            }

            $purged[$key] = true;
            try {
                $this->repository->purge($uri);
            } catch (CacheStoreException $e) {
                $failure ??= $e;
            }
        }

        return $failure;
    }
}
