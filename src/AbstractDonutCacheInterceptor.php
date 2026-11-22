<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

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

/** @internal */
abstract class AbstractDonutCacheInterceptor implements MethodInterceptor
{
    public function __construct(
        private readonly DonutRepositoryInterface $donutRepository,
        private readonly CacheFailure $failure,
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
        $start = (float) hrtime(true);
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

    private function closeGet(string $openId, float $start, bool $hit): void
    {
        $durationMs = round(((float) hrtime(true) - $start) / 1e6, 3);
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
            $this->failure->degrade((string) $ro->uri, 'read', $e);

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
            return $this->put($this->donutRepository, $ro);
        } catch (Throwable $e) {
            $this->failure->degrade((string) $ro->uri, 'write', $e);

            return $ro;
        }
    }

    abstract protected function put(DonutRepositoryInterface $donutRepository, ResourceObject $ro): ResourceObject;
}
