<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\HttpCacheInterface as DeprecatedHttpCacheInterface;
use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\ConditionalRequestContext;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;

use function http_response_code;

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
        $hit = $this->storage->hasEtag($ifNoneMatch);
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
}
