<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ReturnValueIsNotResourceObjectException;
use BEAR\QueryRepository\Log\Context\CommandResultContext;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\CacheLog;
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
 * #[Cacheable] nor #[CacheableResponse]. It purges without a GET, as nothing stores the resource.
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
                $this->purgeSelf($invocation, $ro);
                $this->command->command($invocation, $ro);
            }
        } finally {
            $this->logger->close(new CommandResultContext($ro->code), $openId);
        }

        return $ro;
    }

    /**
     * Skipped where DonutCommandInterceptor does it (#[CacheableResponse]) or where there is no key (no onGet(), no URI)
     *
     * @param MethodInvocation<object> $invocation
     */
    private function purgeSelf(MethodInvocation $invocation, ResourceObject $ro): void
    {
        /** @var AbstractUri|null $uri a resource object taken from the injector has none */
        $uri = $ro->uri;
        if ($uri === null || ! method_exists($ro, 'onGet') || (new ReflectionClass($ro))->getAttributes(CacheableResponse::class) !== []) {
            return;
        }

        $this->refreshSameCommand->purge($invocation, $ro);
    }
}
