<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\HttpCacheInterface as DeprecatedHttpCacheInterface;
use BEAR\QueryRepository\Log\Context\CacheErrorContext;
use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\ConditionalRequestContext;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Throwable;

use function http_response_code;
use function is_string;

/** @psalm-suppress DeprecatedInterface for BC */
final readonly class HttpCache implements HttpCacheInterface, DeprecatedHttpCacheInterface
{
    public function __construct(
        private ResourceStorageInterface $storage,
        private SemanticLoggerInterface $logger = new NullSemanticLogger(),
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * The answer is recorded as its own conditional_request scope: a hit here is the
     * 304 decision, the whole request served without running the resource - the one
     * cache event no get scope can ever show. No If-None-Match presents nothing, so
     * nothing is recorded.
     */
    #[Override]
    public function isNotModified(array $server): bool
    {
        if (! isset($server[Header::HTTP_IF_NONE_MATCH])) {
            return false;
        }

        $ifNoneMatch = $server[Header::HTTP_IF_NONE_MATCH];
        $openId = $this->logger->open(new ConditionalRequestContext($ifNoneMatch));
        try {
            $hit = $this->storage->hasEtag($ifNoneMatch);
        } catch (Throwable $e) {
            // The pool read failed: record the outage and close as the established idiom
            // reads it (cache_error + cache_miss = degraded, lone miss = cold), then let
            // the exception keep its pre-existing path - whether the 304 check should
            // degrade to a full response instead is the same behavior decision as the
            // donut write and goes to the same issue.
            $this->logger->event(new CacheErrorContext($this->requestUri($server), 'read', $e->getMessage(), $e::class));
            $this->logger->close(new CacheMissContext('etag'), $openId);

            throw $e;
        }

        $this->logger->close($hit ? new CacheHitContext('etag') : new CacheMissContext('etag'), $openId);

        return $hit;
    }

    /**
     * {@inheritDoc}
     *
     * @return void
     */
    #[Override]
    public function transfer()
    {
        // @codeCoverageIgnoreStart
        http_response_code(304);
        // @codeCoverageIgnoreEnd
    }

    /**
     * The request URI when the SAPI array carries one
     *
     * The interface shape names only the validator key, but the real $_SERVER carries the
     * whole request; empty means no URI was resolvable at the transfer boundary.
     *
     * @param array<array-key, mixed> $server
     */
    private function requestUri(array $server): string
    {
        /** @var mixed $uri */
        $uri = $server['REQUEST_URI'] ?? '';

        return is_string($uri) ? $uri : '';
    }
}
