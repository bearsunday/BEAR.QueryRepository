<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\QueryRepository\Log\Context\CacheErrorContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\FactoryInterface;
use BEAR\Resource\InvokerInterface;
use BEAR\Resource\Request;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Aop\MethodInvocation;
use ReflectionException;

/** @internal */
final readonly class RefreshSameCommand implements CommandInterface
{
    public function __construct(
        private QueryRepositoryInterface $repository,
        private MatchQueryInterface $matchQuery,
        private FactoryInterface $factory,
        private InvokerInterface $invoker,
        private UnsafeMethodMatcher $unsafeMethod,
        #[CacheLog]
        private SemanticLoggerInterface $logger,
        private CommandQuery $commandQuery,
    ) {
    }

    #[Override]
    public function command(MethodInvocation $invocation, ResourceObject $ro): void
    {
        try {
            $getQuery = $this->getQuery($ro);
        } catch (UnmatchedQuery $e) {
            $this->skipUnmatched($invocation, $ro, $e);

            return;
        }

        foreach ($this->ownUris($ro, $getQuery) as $uri) {
            $this->repository->purge($uri);
        }

        // Read to rebuild the cache entry; with the exact $getQuery, default-valued parameters included
        $get = $this->factory->newInstance($this->withoutQuery($ro));
        (new Request($this->invoker, $get))($getQuery);
    }

    /**
     * A POST to a class that creates rather than addresses an entity lacks onGet()'s required parameters: it is recorded and
     * skipped, and the next command still runs. Another method addresses an existing entity, so the mismatch is thrown.
     *
     * @param MethodInvocation<object> $invocation
     */
    public function skipUnmatched(MethodInvocation $invocation, ResourceObject $ro, UnmatchedQuery $e): void
    {
        if (! $this->unsafeMethod->isPost($invocation->getMethod())) {
            throw $e;
        }

        $this->logger->event(new CacheErrorContext($this->withoutQuery($ro), 'write', $e->getMessage(), $e::class));
    }

    /** The resource's URI without its query, which holds the values the write was sent */
    public function withoutQuery(ResourceObject $ro): string
    {
        return "{$ro->uri->scheme}://{$ro->uri->host}{$ro->uri->path}";
    }

    /**
     * The URIs whose tags are the resource's own key: its onGet() query without the default-valued parameters, as
     * BaseUriTag drops them, and its snake_case spelling, which a GET stored under the snake_case key keeps in its
     * group tag
     *
     * @param array<string, mixed> $getQuery what MatchQueryInterface read from the resource's URI
     *
     * @return list<AbstractUri>
     */
    public function ownUris(ResourceObject $ro, array $getQuery): array
    {
        $delUri = clone $ro->uri;
        $baseQuery = $this->commandQuery->baseQuery($ro, $getQuery);
        $delUri->query = $baseQuery;
        $snakeQuery = $this->commandQuery->snakeQuery($baseQuery);
        if ($snakeQuery === $baseQuery) {
            return [$delUri];
        }

        $snakeUri = clone $ro->uri;
        $snakeUri->query = $snakeQuery;

        return [$delUri, $snakeUri];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ReflectionException
     * @throws UnmatchedQuery
     */
    private function getQuery(ResourceObject $ro): array
    {
        return $this->matchQuery->__invoke($ro);
    }
}
