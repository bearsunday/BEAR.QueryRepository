<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\Resource\FactoryInterface;
use BEAR\Resource\InvokerInterface;
use BEAR\Resource\Request;
use BEAR\Resource\ResourceObject;
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
        private CommandQuery $commandQuery,
        private CommandScope $scope,
    ) {
    }

    #[Override]
    public function command(MethodInvocation $invocation, ResourceObject $ro): void
    {
        try {
            $getQuery = $this->getQuery($ro);
        } catch (UnmatchedQuery $e) {
            $this->scope->skipUnmatched($invocation, $ro, $e);

            return;
        }

        foreach ($this->commandQuery->ownUris($ro, $getQuery) as $uri) {
            $this->repository->purge($uri);
        }

        // Read to rebuild the cache entry; with the exact $getQuery, default-valued parameters included
        $get = $this->factory->newInstance($this->commandQuery->withoutQuery($ro));
        (new Request($this->invoker, $get))($getQuery);
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
