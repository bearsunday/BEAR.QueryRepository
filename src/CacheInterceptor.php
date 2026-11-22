<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\GetContext;
use BEAR\QueryRepository\Log\Context\PurgeContext;
use BEAR\QueryRepository\Log\Context\PutSkippedContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;
use Throwable;

use function assert;
use function hrtime;
use function round;

/**
 * Interceptor for TTL-based caching on CQRS queries with #[Cacheable]
 *
 * Bound to query methods (onGet) of classes marked with #[Cacheable].
 *
 * @see \BEAR\RepositoryModule\Annotation\Cacheable
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#cacheable
 * @internal
 */
final readonly class CacheInterceptor implements MethodInterceptor
{
    public function __construct(
        private QueryRepositoryInterface $repository,
        private CacheFailure $failure,
        private ResourceStorageInterface $storage,
        private UriTagInterface $uriTag,
        #[CacheLog]
        private SemanticLoggerInterface $logger,
    ) {
    }

    #[Override]
    public function invoke(MethodInvocation $invocation)
    {
        $ro = $invocation->getThis();
        assert($ro instanceof ResourceObject);
        $openId = $this->logger->open(new GetContext((string) $ro->uri));
        $start = (float) hrtime(true);
        try {
            [$answer, $hit] = $this->answer($invocation, $ro);
        } catch (Throwable $e) {
            $this->closeGet($openId, $start, hit: false);

            throw $e;
        }

        $this->closeGet($openId, $start, hit: $hit);

        return $answer;
    }

    /**
     * @param MethodInvocation<object> $invocation
     *
     * @return array{ResourceObject, bool} the response, and whether the store answered it
     */
    private function answer(MethodInvocation $invocation, ResourceObject $ro): array
    {
        try {
            $state = $this->repository->get($ro->uri);
        } catch (Throwable $e) {
            $this->failure->degrade((string) $ro->uri, 'read', $e);

            /** @var ResourceObject $unstored */
            $unstored = $invocation->proceed();

            return [$unstored, false];
        }

        if ($state instanceof ResourceState) {
            $state->visit($ro);

            return [$ro, true];
        }

        return [$this->store($invocation), false];
    }

    /** @param MethodInvocation<object> $invocation */
    private function store(MethodInvocation $invocation): ResourceObject
    {
        /** @psalm-suppress MixedAssignment */
        $ro = $invocation->proceed();
        assert($ro instanceof ResourceObject);
        try {
            if ($ro->code !== 200) {
                // Record the actual non-200 code; without it the purge below reads
                // as if a 203 and a 404 were the same thing.
                $this->logger->event(new PutSkippedContext((string) $ro->uri, 'error-code', $ro->code));
                // Only this URI's own tag: purge() would also evict its query variants.
                $this->logger->event(new PurgeContext((string) $ro->uri));
                $this->storage->invalidateTags([($this->uriTag)($ro->uri)]);

                return $ro;
            }

            $this->repository->put($ro);
        } catch (Throwable $e) {
            $this->failure->degrade((string) $ro->uri, 'write', $e);
        }

        return $ro;
    }

    private function closeGet(string $openId, float $start, bool $hit): void
    {
        $durationMs = round(((float) hrtime(true) - $start) / 1e6, 3);
        $this->logger->close(
            $hit ? new CacheHitContext('resource', $durationMs) : new CacheMissContext('resource', $durationMs),
            $openId,
        );
    }
}
