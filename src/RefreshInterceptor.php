<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ReturnValueIsNotResourceObjectException;
use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\QueryRepository\Log\Context\CommandResultContext;
use BEAR\QueryRepository\Log\Context\PurgeSkippedContext;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\RefreshCache;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;
use ReflectionClass;

use function method_exists;

/**
 * Interceptor for the writes of a class that does not store its GET: #[Purge]/#[Refresh], and its own key
 *
 * Bound to #[Purge]/#[Refresh] methods of non-Cacheable classes and to every command method of a class with neither
 * #[Cacheable] nor #[CacheableResponse]. Each URI its own key and its #[Purge]/#[Refresh] name is purged once; it GETs
 * only what #[Refresh] names, as nothing stores the resource.
 *
 * @see \BEAR\RepositoryModule\Annotation\Purge
 * @see \BEAR\RepositoryModule\Annotation\Refresh
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#event-driven-content
 * @internal
 */
final readonly class RefreshInterceptor implements MethodInterceptor
{
    private CommandContextFactory $commandContextFactory;

    public function __construct(
        private RefreshAnnotatedCommand $command,
        private RefreshSameCommand $refreshSameCommand,
        private MatchQueryInterface $matchQuery,
        private QueryRepositoryInterface $repository,
        private RefreshTemplateValidator $refreshTemplateValidator,
        #[CacheLog]
        private SemanticLoggerInterface $logger = new NullSemanticLogger(),
    ) {
        $this->commandContextFactory = new CommandContextFactory();
    }

    #[Override]
    public function invoke(MethodInvocation $invocation): ResourceObject
    {
        ($this->refreshTemplateValidator)($invocation);
        /** @psalm-suppress MixedAssignment */
        $ro = $invocation->proceed();
        if (! $ro instanceof ResourceObject) {
            throw new ReturnValueIsNotResourceObjectException($invocation->getThis()::class);
        }

        // Open the scope even for a failed write: a 4xx command_result with no invalidation
        // events records that the purge/refresh was correctly skipped.
        $openId = $this->logger->open(($this->commandContextFactory)($invocation, 'RefreshInterceptor'));
        try {
            if ($ro->code < Code::BAD_REQUEST) {
                $declared = $this->command->declared($invocation, $ro);
                $uris = $this->ownUris($invocation, $ro);
                foreach ($declared as [$named]) {
                    $uris = [...$uris, ...$named];
                }

                $this->purge($uris);
                foreach ($declared as [$named, $refresh]) {
                    if ($refresh) {
                        $this->command->refresh($named);
                    }
                }
            }
        } finally {
            $this->logger->close(new CommandResultContext($ro->code), $openId);
        }

        return $ro;
    }

    /**
     * None where DonutCommandInterceptor refreshes the resource, or where it has no key: no URI, no onGet(), or a query
     * its onGet() cannot read
     *
     * @param MethodInvocation<object> $invocation
     *
     * @return list<AbstractUri>
     */
    private function ownUris(MethodInvocation $invocation, ResourceObject $ro): array
    {
        /** @var AbstractUri|null $uri a resource object taken from the injector has none */
        $uri = $ro->uri;
        if ($uri === null || ! method_exists($ro, 'onGet') || $this->refreshedByDonut($invocation, $ro)) {
            return [];
        }

        try {
            return $this->refreshSameCommand->ownUris($ro, ($this->matchQuery)($ro));
        } catch (UnmatchedQuery) {
            $this->logger->event(new PurgeSkippedContext((string) $uri));

            return [];
        }
    }

    /** @param MethodInvocation<object> $invocation */
    private function refreshedByDonut(MethodInvocation $invocation, ResourceObject $ro): bool
    {
        return (new ReflectionClass($ro))->getAttributes(CacheableResponse::class) !== []
            || $invocation->getMethod()->getAnnotation(RefreshCache::class) !== null;
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
