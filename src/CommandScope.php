<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\QueryRepository\Log\Context\CacheErrorContext;
use BEAR\QueryRepository\Log\Context\CommandContext;
use BEAR\QueryRepository\Log\Context\CommandResultContext;
use BEAR\RepositoryModule\Annotation\AbstractCommand;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;
use Closure;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Ray\Aop\MethodInvocation;

/**
 * The logging scope of a write; its commands run only for a result below 400
 *
 * @internal
 */
final readonly class CommandScope
{
    public function __construct(
        #[CacheLog]
        private SemanticLoggerInterface $logger,
        private UnsafeMethodMatcher $unsafeMethod,
        private CommandQuery $commandQuery,
    ) {
    }

    /**
     * A 4xx result opens the scope too: a command_result with no invalidation under it records that the commands were skipped.
     *
     * @param MethodInvocation<object> $invocation
     * @param Closure(): void          $commands
     */
    public function run(MethodInvocation $invocation, ResourceObject $ro, Closure $commands): void
    {
        $openId = $this->logger->open($this->context($invocation));
        try {
            if ($ro->code < Code::BAD_REQUEST) {
                $commands();
            }
        } finally {
            $this->logger->close(new CommandResultContext($ro->code), $openId);
        }
    }

    /**
     * A POST to a class that creates rather than addresses an entity lacks onGet()'s required parameters: it is recorded and
     * its own key skipped, and the URIs it names are still purged. Another method addresses an existing entity, so the
     * mismatch is thrown.
     *
     * @param MethodInvocation<object> $invocation
     */
    public function skipUnmatched(MethodInvocation $invocation, ResourceObject $ro, UnmatchedQuery $e): void
    {
        if (! $this->unsafeMethod->isPost($invocation->getMethod())) {
            throw $e;
        }

        $this->logger->event(new CacheErrorContext($this->commandQuery->withoutQuery($ro), 'write', $e->getMessage(), $e::class));
    }

    /** @param MethodInvocation<object> $invocation */
    private function context(MethodInvocation $invocation): CommandContext
    {
        $method = $invocation->getMethod();
        $annotations = [];
        foreach ($method->getAnnotations() as $annotation) {
            if ($annotation instanceof AbstractCommand) {
                $annotations[] = ['class' => $annotation::class, 'uri' => $annotation->uri];
            }
        }

        return new CommandContext($method->getName(), $annotations);
    }
}
