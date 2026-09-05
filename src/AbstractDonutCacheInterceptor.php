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
                    throw $e;
                }

                $this->triggerWarning($e);

                return $invocation->proceed();
            }

            /** @var ResourceObject $ro */
            $ro = $invocation->proceed();
            // Only a 200 is a representation this cache stores, the same threshold `#[Cacheable]`
            // applies. A page carrying its own ETag was written by the resource, not by this one.
            if (isset($ro->headers[Header::ETAG]) || $ro->code !== Code::OK) {
                // Record why this miss is not followed by a put; without it the log looks like a lost write.
                $hasEtag = isset($ro->headers[Header::ETAG]);
                $this->logger->event(new PutSkippedContext((string) $ro->uri, $hasEtag ? 'etag-present' : 'error-code', $hasEtag ? null : $ro->code));

                return $ro;
            }

            return $this->putRecorded($ro);
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
        }
    }

    /**
     * Run the donut write, degrading a store failure and recording either way
     *
     * A store that refuses the entry leaves a page that was rendered correctly: it is served, and
     * the failure is recorded rather than turned into a 500. What the store did not raise keeps
     * travelling - a template that fails to render is not a slow page, it is a page that does not
     * exist, and swallowing it would answer 200 with nothing in it.
     */
    private function putRecorded(ResourceObject $ro): ResourceObject
    {
        try {
            return static::IS_ENTIRE_CONTENT_CACHEABLE ? // phpcs:ignore - not "self"
                $this->donutRepository->putStatic($ro, null, null) :
                $this->donutRepository->putDonut($ro, null);
        } catch (Throwable $e) {
            $this->logger->event(self::failure((string) $ro->uri, 'write', $e));
            if (! $e instanceof CacheStoreFailure) {
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
