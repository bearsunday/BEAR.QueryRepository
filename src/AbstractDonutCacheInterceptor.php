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

    /**
     * Not promoted or defaulted: the default gate must reuse this instance's own `$mark`,
     * never a disconnected one, so a manually constructed caller's own marks stay visible to
     * it. Built in the constructor body, after `$mark` is assigned.
     */
    private readonly UnstorableGateInterface $gate;

    public function __construct(
        private readonly DonutRepositoryInterface $donutRepository,
        #[CacheLog]
        private readonly SemanticLoggerInterface $logger = new NullSemanticLogger(),
        private readonly UncacheableMarkScopeInterface $mark = new UncacheableMark(),
        private readonly UnstorableWriteObserverInterface $writeObserver = new NullUnstorableWriteObserver(),
        UnstorableGateInterface|null $gate = null,
        private readonly HeaderSetter $headerSetter = new HeaderSetter(new EtagSetter()),
    ) {
        $this->gate = $gate ?? new UnstorableGate($this->mark, new DeclaredCacheControl());
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
        // $threw is set before any genuine exception is rethrown, so the outer finally never
        // raises a pending dev-gate skip while it is in flight. This interceptor opens no
        // write scope of its own, unlike CacheInterceptor: isCurrentWriteFor() keeps a nested
        // direct put() any-owner instead of inheriting a scope it doesn't own.
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
     * Runs on a fresh render, whether a normal MISS or a read-degrade fallback: an
     * already-answered response (own ETag, or an error code) is served as-is and recorded;
     * anything else is rendered and stored through `putRecorded()`. An ETag here may be a
     * side effect of a direct `put()` made from this page's own `onGet()`, not necessarily
     * its own validator - `refuseIfUnstorable()` tells the two apart and is a no-op unless
     * also marked.
     *
     * @param bool $threw set by reference, forwarded to putRecorded() and refuseIfUnstorable()
     *     - see those methods' own docblocks.
     */
    private function handleProceedResult(ResourceObject $ro, bool &$threw): ResourceObject
    {
        if (isset($ro->headers[Header::ETAG]) || $ro->code >= Code::BAD_REQUEST) {
            $hasEtag = isset($ro->headers[Header::ETAG]);
            // report: only an ETag-shortcut refusal is dev-gate-reportable - a top-level page
            // marking itself and separately answering an error code must not newly raise.
            $unstorable = $this->refuseIfUnstorable($ro, report: $hasEtag, threw: $threw);
            // Record why this miss is not followed by a put; without it the log looks like a
            // lost write. 'unstorable' when the gate refused it, otherwise the original
            // already-answered reason - code stays null whenever the reason is 'unstorable',
            // per put_skipped's own schema contract (code is the status code "when reason is
            // error-code; null otherwise"), not just whenever an ETag happens to be present.
            $this->logger->event(new PutSkippedContext((string) $ro->uri, $unstorable ?? ($hasEtag ? 'etag-present' : 'error-code'), $unstorable === null && ! $hasEtag ? $ro->code : null));

            return $ro;
        }

        return $this->putRecorded($ro, $threw);
    }

    /**
     * Refuses and purges a response that bypasses the put path (an ETag already set, or an
     * error code) when it is unstorable. Uses the any-owner `reason()` because the live
     * response carries hole content. `$report` is true only on the ETag path, where the
     * template-scoped predicate decides what the dev gate hears.
     *
     * @param bool $threw set by reference so the caller's own finally can tell a genuine
     *     rethrow here apart from a degraded CacheStoreFailure.
     *
     * @return 'unstorable'|null
     */
    private function refuseIfUnstorable(ResourceObject $ro, bool $report, bool &$threw): string|null
    {
        $unstorable = $this->gate->reason($ro);
        if ($unstorable === null) {
            return null;
        }

        // Computed BEFORE the headerSetter call below mutates $ro's own headers:
        // reasonFor()'s own Cache-Control fallback (UnstorableGate::reasonFor()) reads
        // $ro->headers[Cache-Control] too, which the headerSetter call is about to
        // overwrite to 'private, no-store' - reading reasonExcludingHoleMarks() AFTER that
        // call would see ITS OWN just-written header and report 'unstorable' regardless of
        // isMarkedForCurrentScope(), confounding the predicate split entirely.
        $reportable = $report
            ? (static::IS_ENTIRE_CONTENT_CACHEABLE ? $unstorable : $this->gate->reasonExcludingHoleMarks($ro)) // phpcs:ignore - not "self"
            : null;

        ($this->headerSetter)($ro, null, null, unstorable: $unstorable, wasMarked: $this->gate->isMarkReason($ro));
        try {
            $this->donutRepository->purge($ro->uri);
        } catch (Throwable $e) {
            // A pool outage degrades to a warning, same as putRecorded()'s own write failure:
            // the response was already rendered and the header refusal already applied, so
            // this already-answered response is still served - only the stale cache entry
            // survives a beat longer. Anything else is a genuine defect and keeps travelling.
            $this->logger->event(self::failure((string) $ro->uri, 'write', $e));
            if (! $e instanceof CacheStoreFailure) {
                $threw = true;

                throw $e;
            }

            $this->triggerWarning($e);
        }

        if ($reportable !== null) {
            $this->writeObserver->skipped($ro, $reportable);
        }

        return $unstorable;
    }

    /**
     * Runs the donut write, degrading a store failure and recording either way. A refused
     * store still serves the already-rendered page; anything else (a render failure) keeps
     * propagating, since swallowing it would answer 200 with nothing in it.
     *
     * @param bool $threw set by reference, so the caller's own finally can tell a genuine
     *     rethrow apart from a degraded CacheStoreFailure.
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
