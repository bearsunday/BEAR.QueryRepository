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
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Aop\MethodInvocation;
use ReflectionException;

use function str_starts_with;

/** @internal */
final readonly class RefreshSameCommand implements CommandInterface
{
    public function __construct(
        private QueryRepositoryInterface $repository,
        private MatchQueryInterface $matchQuery,
        private FactoryInterface $factory,
        private InvokerInterface $invoker,
        #[CacheLog]
        private SemanticLoggerInterface $logger = new NullSemanticLogger(),
        private CommandQuery $commandQuery = new CommandQuery(),
    ) {
    }

    #[Override]
    public function command(MethodInvocation $invocation, ResourceObject $ro): void
    {
        try {
            $getQuery = $this->getQuery($ro);
        } catch (UnmatchedQuery $e) {
            // #214 bound this command to onPost too, on classes that create rather than address
            // an existing entity - onGet's required parameters (an id assigned on write) are
            // routinely absent from a POST's own query. Pre-#214, onPost reached no command at
            // all, so this was silently a no-op; skipping here restores that shape instead of a
            // regression, and an explicit #[Refresh]/#[Purge] on the method still runs next
            // (CommandsProvider orders this command first, but returning - not throwing - lets
            // CommandInterceptor's loop reach the next command).
            //
            // onPut/onPatch/onDelete keep throwing: those act on an entity onGet already
            // addresses, so a required parameter missing there is a real signature mismatch, not
            // this case (BehaviorTest::testUnMatchQuery pins that).
            if (! str_starts_with($invocation->getMethod()->getName(), 'onPost')) {
                throw $e;
            }

            // Without the query, which holds the values the write was sent
            $this->logger->event(new CacheErrorContext("{$ro->uri->scheme}://{$ro->uri->host}{$ro->uri->path}", 'write', $e->getMessage(), $e::class));

            return;
        }

        // delete data in repository
        foreach ($this->ownUris($ro, $getQuery) as $uri) {
            $this->repository->purge($uri);
        }

        // Read to rebuild the cache entry; with the exact $getQuery, default-valued parameters included
        $get = $this->factory->newInstance("{$ro->uri->scheme}://{$ro->uri->host}{$ro->uri->path}");
        (new Request($this->invoker, $get))($getQuery);
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
