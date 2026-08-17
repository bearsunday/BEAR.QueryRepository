<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\CacheStoreFailure;
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
use function hrtime;
use function round;
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
        // On a hit this is the pool read; on a miss the resource run and the write it triggered.
        $openId = $this->logger->open(new GetContext((string) $ro->uri));
        $start = hrtime(true);
        $hit = false;
        try {
            try {
                $state = $this->repository->get($ro->uri);
            } catch (Throwable $e) {
                // Recorded either way; the store's own failure is the only one this path swallows.
                // Running the resource answers the request correctly, so a pool outage costs
                // latency instead of a 500 - but a defect here that returned 200 would be a defect
                // nobody sees, so anything else keeps travelling (issue #190).
                $this->logger->event(self::failure((string) $ro->uri, 'read', $e));
                if (! $e instanceof CacheStoreFailure) {
                    throw $e;
                }

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
            } catch (Throwable $e) {
                // The store refused the write: the response is already correct, so it is served
                // and the failure is recorded. A render that throws, or a CDN purge that does, is
                // not the store's failure and keeps its path - see issue #190.
                $this->logger->event(self::failure((string) $ro->uri, 'write', $e));
                if (! $e instanceof CacheStoreFailure) {
                    throw $e;
                }

                $this->triggerWarning($e);
            }

            return $ro;
        } finally {
            $durationMs = round((hrtime(true) - $start) / 1_000_000, 3);
            // Psalm mis-tracks the $hit flag mutated inside try when read from finally.
            /** @psalm-suppress RedundantCondition, TypeDoesNotContainType */
            $this->logger->close(
                $hit ? new CacheHitContext('resource', $durationMs) : new CacheMissContext('resource', $durationMs),
                $openId,
            );
        }
    }

    /**
     * A failure on the cache path degrades to a warning rather than an exception, so the
     * request is still served: the cache is an optimization, not a dependency.
     */

    /**
     * Record the throwable that actually failed, not the boundary wrapper
     *
     * `CacheStoreFailure` says where the failure happened; its cause says what it was (a Redis
     * timeout, a marshalling error), and that is the half a reader needs.
     */

    /** @param 'read'|'write' $operation */
    private static function failure(string $uri, string $operation, Throwable $e): CacheErrorContext
    {
        $cause = $e instanceof CacheStoreFailure ? $e->getPrevious() ?? $e : $e;

        return new CacheErrorContext($uri, $operation, $cause->getMessage(), $cause::class);
    }

    private function triggerWarning(Throwable $e): void
    {
        $message = sprintf('%s: %s in %s:%s', $e::class, $e->getMessage(), $e->getFile(), $e->getLine());
        trigger_error($message, E_USER_WARNING);
    }
}
