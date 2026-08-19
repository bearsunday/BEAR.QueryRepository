<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\LogicException;
use BEAR\QueryRepository\Log\Context\CacheErrorContext;
use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\GetContext;
use BEAR\QueryRepository\Log\Context\PutSkippedContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;
use Throwable;

use function assert;
use function sprintf;
use function trigger_error;

use const E_USER_WARNING;

/**
 * Interceptor for TTL-based caching on CQRS queries with #[Cacheable]
 *
 * Bound to query methods (onGet) of classes marked with #[Cacheable].
 * Retrieves cached resource state if available, otherwise executes
 * the method and stores the result with configured TTL.
 *
 * @see \BEAR\RepositoryModule\Annotation\Cacheable
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#cacheable
 */
final readonly class CacheInterceptor implements MethodInterceptor
{
    public function __construct(
        private QueryRepositoryInterface $repository,
        #[CacheLog]
        private SemanticLoggerInterface $logger = new NullSemanticLogger(),
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * Opens a GET scope so embedded child resources fetched during put() nest
     * under this resource, and the scope is closed with the hit/miss outcome.
     */
    #[Override]
    public function invoke(MethodInvocation $invocation)
    {
        $ro = $invocation->getThis();
        assert($ro instanceof ResourceObject);
        $openId = $this->logger->open(new GetContext((string) $ro->uri));
        $hit = false;
        try {
            try {
                $state = $this->repository->get($ro->uri);
            } catch (Throwable $e) {
                // The cache read path is degraded: log it so a miss here is not read as a cold cache
                $this->logger->event(new CacheErrorContext((string) $ro->uri, 'read', $e->getMessage(), $e::class));
                $this->triggerWarning($e);

                return $invocation->proceed();
            }

            if ($state instanceof ResourceState) {
                $state->visit($ro);
                $hit = true;

                return $ro;
            }

            /** @psalm-suppress MixedAssignment */
            $ro = $invocation->proceed();
            assert($ro instanceof ResourceObject);
            try {
                if ($ro->code !== 200) {
                    // Record the actual non-200 code; without it the purge below reads
                    // as if a 203 and a 404 were the same thing.
                    $this->logger->event(new PutSkippedContext((string) $ro->uri, 'error-code', $ro->code));
                    $this->repository->purge($ro->uri);

                    return $ro;
                }

                $this->repository->put($ro);
            } catch (LogicException $e) {
                throw $e;
            } catch (Throwable $e) {
                // Anything the store path raised, pool outage or not (a view that fails to
                // render, a CDN purge): the class says which, so the reader is not guessing.
                $this->logger->event(new CacheErrorContext((string) $ro->uri, 'write', $e->getMessage(), $e::class));
                $this->triggerWarning($e);
            }

            return $ro;
        } finally {
            // Psalm mis-tracks the $hit flag mutated inside try when read from finally.
            /** @psalm-suppress RedundantCondition, TypeDoesNotContainType */
            $this->logger->close(
                $hit ? new CacheHitContext('resource') : new CacheMissContext('resource'),
                $openId,
            );
        }
    }

    /**
     * A failure on the cache path degrades to a warning rather than an exception, so the
     * request is still served: the cache is an optimization, not a dependency.
     */
    private function triggerWarning(Throwable $e): void
    {
        $message = sprintf('%s: %s in %s:%s', $e::class, $e->getMessage(), $e->getFile(), $e->getLine());
        trigger_error($message, E_USER_WARNING);
    }
}
