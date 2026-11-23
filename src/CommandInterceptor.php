<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ReturnValueIsNotResourceObjectException;
use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\QueryRepository\Log\Context\PurgeSkippedContext;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\RefreshCache;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\FactoryInterface;
use BEAR\Resource\InvokerInterface;
use BEAR\Resource\Request;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;
use ReflectionAttribute;
use ReflectionClass;

use function array_column;
use function array_merge;
use function method_exists;

/**
 * After a write that answers below 400: purges the resource's own key and every URI its #[Purge]/#[Refresh] name, then
 * reads its own URI again where the write stores it, then each #[Refresh] URI
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
        #[CacheLog]
        private SemanticLoggerInterface $logger,
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
        $declared = $this->command->declared($invocation, $ro);
        $own = $sent === null || $getQuery === null ? [] : $this->commandQuery->ownUris($sent, $getQuery);
        $this->purge([...$own, ...array_merge(...array_column($declared, 0))]);
        if ($stored && $sent !== null && $getQuery !== null) {
            $this->rebuild($invocation, $sent, $getQuery);
        }

        foreach ($declared as [$named, $refresh]) {
            if ($refresh) {
                $this->command->refresh($named);
            }
        }
    }

    /** The resource the write was sent to, cloned with its URI before the handler runs; none without a URI */
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
        $class = new ReflectionClass($invocation->getThis());
        $method = $invocation->getMethod();

        return $class->getAttributes(Cacheable::class, ReflectionAttribute::IS_INSTANCEOF) !== []
            || $class->getAttributes(CacheableResponse::class, ReflectionAttribute::IS_INSTANCEOF) !== []
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
            if ($stored) {
                $this->scope->skipUnmatched($invocation, $sent, $e);

                return null;
            }

            $this->logger->event(new PurgeSkippedContext($this->commandQuery->withoutQuery($sent), PurgeSkippedContext::UNMATCHED_QUERY));

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
            $this->scope->skipUnmatched($invocation, $sent, $e);
        }
    }

    /**
     * Each URI once, compared as the string purge() is given: its own key and a #[Purge] of itself are one purge
     *
     * @param list<AbstractUri> $uris
     */
    private function purge(array $uris): void
    {
        $purged = [];
        foreach ($uris as $uri) {
            $key = (string) $uri;
            if (isset($purged[$key])) {
                continue;
            }

            $purged[$key] = true;
            $this->repository->purge($uri);
        }
    }
}
