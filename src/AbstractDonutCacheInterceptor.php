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
use BEAR\Resource\Code;
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

abstract class AbstractDonutCacheInterceptor implements MethodInterceptor
{
    protected const IS_ENTIRE_CONTENT_CACHEABLE = false;

    public function __construct(
        private readonly DonutRepositoryInterface $donutRepository,
        #[CacheLog]
        private readonly SemanticLoggerInterface $logger = new NullSemanticLogger(),
        private readonly UncacheableMarkInterface $mark = new UncacheableMark(),
        private readonly UnstorableWriteObserverInterface $writeObserver = new NullUnstorableWriteObserver(),
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * Opens a donut GET scope so embedded resources rebuilt during get()/put nest
     * under this page, and the scope is closed with the donut-view hit/miss outcome.
     */
    #[Override]
    final public function invoke(MethodInvocation $invocation)
    {
        $ro = $invocation->getThis();
        assert($ro instanceof ResourceObject);
        // What the answer cost: a hit reads the recomposed view, a miss renders it.
        $openId = $this->logger->open(new GetContext((string) $ro->uri));
        $start = hrtime(true);
        $hit = false;
        // See CacheInterceptor's own copy of this flag and comment: set before ANY genuine
        // (non-CacheStoreFailure) exception - from the resource's own proceed(), or from the
        // store's putRecorded() - is rethrown below, so the outer finally never raises a
        // pending dev-gate skip while that exception is already on its way out. No write
        // scope of its own (unlike CacheInterceptor): donutRepository->putStatic()/putDonut()
        // never read wasMarkedSinceCurrentWrite() (any-owner isMarked()/isMarkedForCurrentScope()
        // only), and a borrowed scope here would let a direct, non-AOP QueryRepository::put()
        // nested inside this page's own rendering wrongly inherit it - isCurrentWriteFor()
        // (UncacheableMarkInterface) is what keeps that write safely any-owner instead.
        $threw = false;
        $this->mark->enterInterceptor();
        try {
            try {
                $maybeRo = $this->donutRepository->get($ro);
                if ($maybeRo instanceof ResourceObject) {
                    $hit = true;

                    return $maybeRo;
                }
            } catch (Throwable $e) {
                // when cache server is down: log it so a miss here is not read as a cold cache
                $this->logger->event(self::failure((string) $ro->uri, 'read', $e));
                if (! $e instanceof CacheStoreFailure) {
                    $threw = true;

                    throw $e;
                }

                $this->triggerWarning($e);

                /** @var ResourceObject $fresh */
                $fresh = $this->proceedTrackingThrew($invocation, $threw);

                // Same outcome a normal MISS reaches below: render (and, if the render and
                // the store both succeed, re-cache) via the full donut path - a degraded
                // read must not return $invocation->proceed()'s raw, un-rendered result
                // (an un-executed #[Embed] request, `view` still null) directly to the
                // caller, which would either show nothing or make a later `(string)` cast
                // execute a hole untracked by UncacheableMark/SurrogateKeys, or - for a
                // raw (non-donut-wrapped) AbstractRequest - trigger its own E_USER_ERROR.
                return $this->handleProceedResult($fresh, $threw);
            }

            /** @var ResourceObject $ro */
            $ro = $this->proceedTrackingThrew($invocation, $threw);

            return $this->handleProceedResult($ro, $threw);
        } finally {
            $durationMs = round((hrtime(true) - $start) / 1_000_000, 3);
            // Psalm mis-tracks the $hit flag mutated inside try when read from finally.
            /** @psalm-suppress RedundantCondition, TypeDoesNotContainType */
            $this->logger->close(
                $hit
                    ? new CacheHitContext('donut-view', $durationMs)
                    : new CacheMissContext('donut-view', $durationMs),
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
     * #234 dev gate: report a pending skip once the OUTERMOST cacheable interceptor (depth
     * back to zero) returns NORMALLY, never mid-render. See CacheInterceptor's own copy.
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
     * $invocation->proceed(), with $threw set by reference if it throws - see CacheInterceptor's
     * own copy of this check.
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
     * What runs on a fresh render, whether it is a normal MISS or a read-degrade fallback:
     * an already-answered response (an ETag the resource set itself, an error code) is
     * served as-is and the non-write recorded; anything else is rendered and stored through
     * `putRecorded()`.
     *
     * @param bool $threw set by reference, forwarded to putRecorded() - see that method's own
     *     docblock.
     */
    private function handleProceedResult(ResourceObject $ro, bool &$threw): ResourceObject
    {
        // donut created in ResourceObject
        if (isset($ro->headers[Header::ETAG]) || $ro->code >= Code::BAD_REQUEST) {
            // Record why this miss is not followed by a put; without it the log looks like a lost write.
            $hasEtag = isset($ro->headers[Header::ETAG]);
            $this->logger->event(new PutSkippedContext((string) $ro->uri, $hasEtag ? 'etag-present' : 'error-code', $hasEtag ? null : $ro->code));

            return $ro;
        }

        return $this->putRecorded($ro, $threw);
    }

    /**
     * Run the donut write, degrading a store failure and recording either way
     *
     * A store that refuses the entry leaves a page that was rendered correctly: it is served, and
     * the failure is recorded rather than turned into a 500. What the store did not raise keeps
     * travelling - a template that fails to render is not a slow page, it is a page that does not
     * exist, and swallowing it would answer 200 with nothing in it.
     *
     * @param bool $threw set by reference so the caller's own finally (reportIfOutermost()) can
     *     tell a genuine rethrow here apart from a degraded CacheStoreFailure - never raising a
     *     pending dev-gate skip in place of the exception this method is about to let travel.
     */
    private function putRecorded(ResourceObject $ro, bool &$threw): ResourceObject
    {
        try {
            return static::IS_ENTIRE_CONTENT_CACHEABLE ? // phpcs:ignore - not "self"
                $this->donutRepository->putStatic($ro, null, null) :
                $this->donutRepository->putDonut($ro, null);
        } catch (Throwable $e) {
            $this->logger->event(self::failure((string) $ro->uri, 'write', $e));
            if (! $e instanceof CacheStoreFailure) {
                $threw = true;

                throw $e;
            }

            $this->triggerWarning($e);

            return $ro;
        }
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

    private function triggerWarning(Throwable $e): void
    {
        trigger_error(sprintf('%s: %s in %s:%s', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()), E_USER_WARNING);
    }
}
