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
        private UncacheableMarkScopeInterface $mark = new UncacheableMark(),
        private UnstorableWriteObserverInterface $writeObserver = new NullUnstorableWriteObserver(),
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
        // Set before ANY genuine (non-CacheStoreFailure) exception - from the resource's own
        // proceed(), or from the store's put() - is rethrown below, so the outer finally never
        // raises a pending dev-gate skip while that exception is already on its way out:
        // raising there would REPLACE it instead of letting it travel (a store failure
        // degrades to a warning and keeps going, so it never sets this).
        $threw = false;
        $this->mark->enterInterceptor();
        try {
            try {
                $state = $this->repository->get($ro->uri);
            } catch (Throwable $e) {
                $state = $this->degradeReadFailure($e, $ro, $threw);
            }

            if ($state instanceof ResourceState) {
                $state->visit($ro);
                $hit = true;

                return $ro;
            }

            return $this->writeFreshResult($ro, $invocation, $threw);
        } finally {
            $durationMs = round((hrtime(true) - $start) / 1_000_000, 3);
            // Psalm mis-tracks the $hit flag mutated inside try when read from finally.
            /** @psalm-suppress RedundantCondition, TypeDoesNotContainType */
            $this->logger->close(
                $hit ? new CacheHitContext('resource', $durationMs) : new CacheMissContext('resource', $durationMs),
                $openId,
            );
            // #234 dev gate: report a pending skip once the OUTERMOST cacheable interceptor
            // (depth back to zero) returns NORMALLY - $threw means a genuine exception is
            // already propagating out of this same finally, so the skip is dropped without
            // being raised instead (never silently lost: put_skipped already recorded it).
            $this->reportIfOutermost($threw);
        }
    }

    /**
     * Run proceed() for a MISS - normal or degraded, same shape either way - and the write
     * it triggers: non-200 purges instead, a 200 is put(). A store failure on the write
     * degrades to a warning, the same as a read failure does; anything else keeps travelling.
     *
     * Opens its own write-scope frame around proceed() so isCurrentWriteFor() matches THIS
     * resource's own write for both a normal miss and a degraded read alike - a direct
     * (non-AOP) put() made from inside onGet() is scoped the same way regardless of which
     * path reached it.
     *
     * @param MethodInvocation<object> $invocation
     */
    private function writeFreshResult(ResourceObject $ro, MethodInvocation $invocation, bool &$threw): ResourceObject
    {
        $this->mark->enterWrite($ro);
        try {
            /** @psalm-suppress MixedAssignment */
            $ro = $this->proceedTrackingThrew($invocation, $threw);

            assert($ro instanceof ResourceObject);
            // proceed()'s return value is not always the same instance enterWrite()
            // above opened this frame with (onGet() may return a freshly built
            // ResourceObject rather than $this) - re-bind now, immediately before the
            // write this frame actually governs, or isCurrentWriteFor() would silently
            // stop matching and this write would quietly fall back to any-owner.
            $this->mark->setWriteOwner($ro);
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
                // The store refused the write: the already-correct response is served and the
                // failure recorded; a render/purge failure is not the store's and keeps propagating.
                $this->logger->event(self::failure((string) $ro->uri, 'write', $e));
                if (! $e instanceof CacheStoreFailure) {
                    $threw = true;

                    throw $e;
                }

                $this->triggerWarning($e);
            }

            return $ro;
        } finally {
            $this->mark->leaveWrite();
        }
    }

    /**
     * A genuine read failure keeps travelling; a store outage (CacheStoreFailure) degrades to
     * a warning and this resource's own read is treated as a MISS - not an early return of
     * proceed()'s raw result, so the SAME write path a normal miss takes below still runs for
     * this resource's own result.
     *
     * A direct (non-AOP) put() made from inside this resource's own onGet() - say, setting a
     * secret and only marking it unstorable afterward - relies on that outer write's own
     * pre-write cleanup (QueryRepository::doPut()'s unconditional deleteEtag()) to remove
     * what the inner write already stored when the OUTER write is then refused; returning
     * proceed()'s result directly here used to skip that outer write entirely, so the inner
     * write's secret survived a degraded read where it would not have survived a normal miss
     * (Kimi's finding).
     *
     * @return null always - the caller reads it as a MISS
     */
    private function degradeReadFailure(Throwable $e, ResourceObject $ro, bool &$threw): null
    {
        // Recorded either way; the store's own failure is the only one this path swallows.
        // Running the resource answers the request correctly, so a pool outage costs latency
        // instead of a 500 - but a defect here that returned 200 would be a defect nobody
        // sees, so anything else keeps travelling.
        $this->logger->event(self::failure((string) $ro->uri, 'read', $e));
        if (! $e instanceof CacheStoreFailure) {
            $threw = true;

            throw $e;
        }

        $this->triggerWarning($e);

        return null;
    }

    /**
     * $invocation->proceed(), with $threw set by reference if it throws - see invoke()'s own
     * $threw comment for why.
     *
     * @param MethodInvocation<object> $invocation
     */
    private function proceedTrackingThrew(MethodInvocation $invocation, bool &$threw): mixed
    {
        try {
            return $invocation->proceed();
        } catch (Throwable $e) {
            $threw = true;

            throw $e;
        }
    }

    /**
     * #234 dev gate: report a pending skip once the OUTERMOST cacheable interceptor (depth
     * back to zero) returns NORMALLY, never mid-render. See AbstractDonutCacheInterceptor's
     * own copy of this check.
     */
    private function reportIfOutermost(bool $threw): void
    {
        if (! $this->mark->leaveInterceptor()) {
            return;
        }

        if ($threw) {
            $this->writeObserver->clearPending();

            return;
        }

        $this->writeObserver->raiseIfPending();
    }

    /**
     * Record the throwable that actually failed, not the boundary wrapper
     *
     * @param 'read'|'write' $operation
     */
    private static function failure(string $uri, string $operation, Throwable $e): CacheErrorContext
    {
        $cause = $e instanceof CacheStoreFailure ? $e->getPrevious() ?? $e : $e;

        return new CacheErrorContext($uri, $operation, $cause->getMessage(), $cause::class);
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
