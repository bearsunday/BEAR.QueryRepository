<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\CacheStoreException;
use BEAR\QueryRepository\Log\Context\CacheErrorContext;
use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\GetContext;
use BEAR\QueryRepository\Log\Context\PutSkippedContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;
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

/** @internal */
abstract class AbstractDonutCacheInterceptor implements MethodInterceptor
{
    protected const IS_ENTIRE_CONTENT_CACHEABLE = false;

    public function __construct(
        private readonly DonutRepositoryInterface $donutRepository,
        #[CacheLog]
        private readonly SemanticLoggerInterface $logger,
    ) {
    }

    #[Override]
    final public function invoke(MethodInvocation $invocation)
    {
        $ro = $invocation->getThis();
        assert($ro instanceof ResourceObject);
        $openId = $this->logger->open(new GetContext((string) $ro->uri));
        $start = hrtime(true);
        try {
            $cached = $this->fromCache($ro);
            $answer = $cached ?? $this->render($invocation);
        } catch (Throwable $e) {
            $this->closeGet($openId, $start, hit: false);

            throw $e;
        }

        $this->closeGet($openId, $start, hit: $cached !== null);

        return $answer;
    }

    /** @param MethodInvocation<object> $invocation */
    private function render(MethodInvocation $invocation): ResourceObject
    {
        /** @var ResourceObject $fresh */
        $fresh = $invocation->proceed();

        // The raw, un-rendered result must not reach the caller: a later cast could run an untracked hole
        return $this->handleProceedResult($fresh);
    }

    private function closeGet(string $openId, int $start, bool $hit): void
    {
        $durationMs = round((hrtime(true) - $start) / 1_000_000, 3);
        $this->logger->close(
            $hit ? new CacheHitContext('donut-view', $durationMs) : new CacheMissContext('donut-view', $durationMs),
            $openId,
        );
    }

    private function fromCache(ResourceObject $ro): ResourceObject|null
    {
        try {
            return $this->donutRepository->get($ro);
        } catch (Throwable $e) {
            $this->logger->event($this->failure((string) $ro->uri, 'read', $e));
            if (! $e instanceof CacheStoreException) {
                throw $e;
            }

            $this->triggerWarning($e);

            return null;
        }
    }

    private function handleProceedResult(ResourceObject $ro): ResourceObject
    {
        if (isset($ro->headers[Header::ETAG]) || $ro->code >= Code::BAD_REQUEST) {
            $hasEtag = isset($ro->headers[Header::ETAG]);
            $this->logger->event(new PutSkippedContext((string) $ro->uri, $hasEtag ? 'etag-present' : 'error-code', $hasEtag ? null : $ro->code));

            return $ro;
        }

        return $this->putRecorded($ro);
    }

    /** Run the donut write, degrading a store failure and recording either way */
    private function putRecorded(ResourceObject $ro): ResourceObject
    {
        try {
            return static::IS_ENTIRE_CONTENT_CACHEABLE ? // phpcs:ignore - not "self"
                $this->donutRepository->putStatic($ro, null, null) :
                $this->donutRepository->putDonut($ro, null);
        } catch (Throwable $e) {
            $this->logger->event($this->failure((string) $ro->uri, 'write', $e));
            if (! $e instanceof CacheStoreException) {
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
    private function failure(string $uri, string $operation, Throwable $e): CacheErrorContext
    {
        $cause = $e instanceof CacheStoreException ? $e->getPrevious() ?? $e : $e;

        return new CacheErrorContext($uri, $operation, $cause->getMessage(), $cause::class);
    }

    private function triggerWarning(Throwable $e): void
    {
        $cause = $e->getPrevious() ?? $e;
        trigger_error(sprintf('%s: %s in %s:%s', $cause::class, $cause->getMessage(), $cause->getFile(), $cause->getLine()), E_USER_WARNING);
    }
}
