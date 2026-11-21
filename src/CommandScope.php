<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

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
    ) {
    }

    /**
     * A 4xx result opens the scope too: a command_result with no invalidation under it records that the commands were skipped.
     *
     * @param MethodInvocation<object> $invocation
     * @param string                   $source     the interceptor, recorded as the scope's source
     * @param Closure(): void          $commands
     */
    public function run(MethodInvocation $invocation, ResourceObject $ro, string $source, Closure $commands): void
    {
        $openId = $this->logger->open($this->context($invocation, $source));
        try {
            if ($ro->code < Code::BAD_REQUEST) {
                $commands();
            }
        } finally {
            $this->logger->close(new CommandResultContext($ro->code), $openId);
        }
    }

    /** @param MethodInvocation<object> $invocation */
    private function context(MethodInvocation $invocation, string $source): CommandContext
    {
        $method = $invocation->getMethod();
        $annotations = [];
        foreach ($method->getAnnotations() as $annotation) {
            if ($annotation instanceof AbstractCommand) {
                $annotations[] = ['class' => $annotation::class, 'uri' => $annotation->uri];
            }
        }

        return new CommandContext($method->getName(), $annotations, $source);
    }
}
