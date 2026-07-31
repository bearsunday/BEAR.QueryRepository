<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\CacheErrorContext;
use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\GetContext;
use BEAR\QueryRepository\Log\NullSemanticLogger;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;
use Throwable;

use function assert;
use function sprintf;
use function trigger_error;

use const E_USER_WARNING;

abstract class AbstractDonutCacheInterceptor implements MethodInterceptor
{
    protected const IS_ENTIRE_CONTENT_CACHEABLE = false;

    public function __construct(
        private readonly DonutRepositoryInterface $donutRepository,
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
        $openId = $this->logger->open(new GetContext((string) $ro->uri));
        $hit = false;
        try {
            try {
                $maybeRo = $this->donutRepository->get($ro);
                if ($maybeRo instanceof ResourceObject) {
                    $hit = true;

                    return $maybeRo;
                }
            } catch (Throwable $e) { // @codeCoverageIgnoreStart
                // when cache server is down: log it so a miss here is not read as a cold cache
                $this->logger->event(new CacheErrorContext((string) $ro->uri, $e->getMessage()));
                $this->triggerWarning($e);

                return $invocation->proceed(); // @codeCoverageIgnoreEnd
            }

            /** @var ResourceObject $ro */
            $ro = $invocation->proceed();
            // donut created in ResourceObject
            if (isset($ro->headers[Header::ETAG]) || $ro->code >= Code::BAD_REQUEST) {
                return $ro;
            }

            return static::IS_ENTIRE_CONTENT_CACHEABLE ? // phpcs:ignore - not "self"
                $this->donutRepository->putStatic($ro, null, null) :
                $this->donutRepository->putDonut($ro, null);
        } finally {
            // Psalm mis-tracks the $hit flag mutated inside try when read from finally.
            /** @psalm-suppress RedundantCondition, TypeDoesNotContainType */
            $this->logger->close(
                $hit ? new CacheHitContext('donut-view') : new CacheMissContext('donut-view'),
                $openId,
            );
        }
    }

    /** @codeCoverageIgnore */
    private function triggerWarning(Throwable $e): void
    {
        trigger_error(sprintf('%s: %s in %s:%s', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()), E_USER_WARNING);
    }
}
