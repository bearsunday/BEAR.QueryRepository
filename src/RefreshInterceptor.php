<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ReturnValueIsNotResourceObjectException;
use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\QueryRepository\Log\Context\PurgeSkippedContext;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\RefreshCache;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;
use ReflectionClass;

use function method_exists;

/**
 * A write's query has to carry what its onGet() requires for its own key to be purged; otherwise only what its
 * #[Purge]/#[Refresh] name is purged, and purge_skipped is logged
 *
 * @internal
 */
final readonly class RefreshInterceptor implements MethodInterceptor
{
    public function __construct(
        private RefreshAnnotatedCommand $command,
        private CommandQuery $commandQuery,
        private MatchQueryInterface $matchQuery,
        private QueryRepositoryInterface $repository,
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

        $this->scope->run($invocation, $ro, 'RefreshInterceptor', function () use ($invocation, $ro, $sent): void {
            $declared = $this->command->declared($invocation, $ro);
            $uris = $sent instanceof ResourceObject ? $this->ownUris($invocation, $sent) : [];
            foreach ($declared as [$named]) {
                $uris = [...$uris, ...$named];
            }

            $this->purge($uris);
            foreach ($declared as [$named, $refresh]) {
                if ($refresh) {
                    $this->command->refresh($named);
                }
            }
        });

        return $ro;
    }

    /** The resource the write was sent to, cloned with its URI before the handler runs; none without a URI */
    private function sent(ResourceObject $target): ResourceObject|null
    {
        /** @var AbstractUri|null $uri a resource object taken from the injector has none */
        $uri = $target->uri;

        return $uri === null ? null : clone $target;
    }

    /**
     * None where DonutCommandInterceptor refreshes the resource, or where it has no key: no onGet(), or a query its
     * onGet() cannot read
     *
     * @param MethodInvocation<object> $invocation
     *
     * @return list<AbstractUri>
     */
    private function ownUris(MethodInvocation $invocation, ResourceObject $ro): array
    {
        if (! method_exists($ro, 'onGet') || $this->refreshedByDonut($invocation, $ro)) {
            return [];
        }

        try {
            return $this->commandQuery->ownUris($ro, ($this->matchQuery)($ro));
        } catch (UnmatchedQuery) {
            $this->logger->event(new PurgeSkippedContext($this->commandQuery->withoutQuery($ro), PurgeSkippedContext::UNMATCHED_QUERY));

            return [];
        }
    }

    /** @param MethodInvocation<object> $invocation */
    private function refreshedByDonut(MethodInvocation $invocation, ResourceObject $ro): bool
    {
        $method = $invocation->getMethod();

        return (new ReflectionClass($ro))->getAttributes(CacheableResponse::class) !== []
            || $method->getAnnotation(CacheableResponse::class) !== null
            || $method->getAnnotation(RefreshCache::class) !== null;
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
