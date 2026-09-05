<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\CacheStoreFailure;
use BEAR\QueryRepository\HttpCacheInterface as DeprecatedHttpCacheInterface;
use BEAR\QueryRepository\Log\Context\CacheErrorContext;
use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\ConditionalRequestContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\AbstractUri;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Throwable;

use function hrtime;
use function http_response_code;
use function is_string;
use function round;
use function sprintf;
use function trigger_error;

use const E_USER_WARNING;

/** @psalm-suppress DeprecatedInterface for BC */
final readonly class HttpCache implements HttpCacheInterface, DeprecatedHttpCacheInterface, UriScopedHttpCacheInterface
{
    public function __construct(
        private ResourceStorageInterface $storage,
        #[CacheLog]
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
        // What answering without running the resource cost: this is the whole request on a hit.
        $openId = $this->logger->open(new ConditionalRequestContext($ifNoneMatch));
        $start = hrtime(true);
        try {
            $hit = $this->storage->hasEtag($ifNoneMatch);
        } catch (CacheStoreFailure $e) {
            // A validator this pool cannot read is a validator that does not match: answering the
            // request in full is the correct response, and the alternative - a 500 because the ETag
            // store is down - fails a request the application could have served. Recorded as the
            // established idiom reads it: cache_error + cache_miss = degraded, a lone miss = cold.
            $cause = $e->getPrevious() ?? $e;
            $this->logger->event(new CacheErrorContext($this->requestUri($server), 'read', $cause->getMessage(), $cause::class));
            $this->logger->close(new CacheMissContext('etag', round((hrtime(true) - $start) / 1_000_000, 3)), $openId);
            $this->triggerWarning($cause);

            return false;
        }

        $durationMs = round((hrtime(true) - $start) / 1_000_000, 3);
        $this->logger->close($hit ? new CacheHitContext('etag', $durationMs) : new CacheMissContext('etag', $durationMs), $openId);

        return $hit;
    }

    /**
     * {@inheritDoc}
     *
     * The same decision as {@see self::isNotModified()}, made against the resource that was asked
     * for: a validator issued for another URI is not this representation's, so the request is
     * answered in full. Recorded as its own `conditional_request` scope, like the unscoped one.
     */
    #[Override]
    public function isNotModifiedFor(AbstractUri $uri, array $server): bool
    {
        if (! isset($server[Header::HTTP_IF_NONE_MATCH])) {
            return false;
        }

        if (! $this->storage instanceof ScopedValidatorInterface) {
            // A storage that cannot scope answers the older question; a reader of the log can
            // still see which validator was presented, but not which question was asked.
            return $this->isNotModified($server);
        }

        $ifNoneMatch = $server[Header::HTTP_IF_NONE_MATCH];
        $openId = $this->logger->open(new ConditionalRequestContext($ifNoneMatch));
        $start = hrtime(true);
        try {
            $hit = $this->storage->hasEtagFor($ifNoneMatch, $uri);
        } catch (CacheStoreFailure $e) {
            // The same decision as the unscoped answer, against the URI that was asked for.
            $cause = $e->getPrevious() ?? $e;
            $this->logger->event(new CacheErrorContext((string) $uri, 'read', $cause->getMessage(), $cause::class));
            $this->logger->close(new CacheMissContext('etag', round((hrtime(true) - $start) / 1_000_000, 3)), $openId);
            $this->triggerWarning($cause);

            return false;
        }

        $durationMs = round((hrtime(true) - $start) / 1_000_000, 3);
        $this->logger->close($hit ? new CacheHitContext('etag', $durationMs) : new CacheMissContext('etag', $durationMs), $openId);

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
        // Ignored, not untested: under CLI this function has no response to set and returns false,
        // so a test could only assert that calling it did nothing. What it does is the SAPI's
        // behaviour, and the decision that leads here is covered where it is made.
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

    /**
     * A failure on the cache path degrades to a warning rather than an exception
     *
     * The same channel the read side of `#[Cacheable]` uses: the host's own monitoring is what
     * notices a pool outage, and the log records it at the point it happened.
     */
    private function triggerWarning(Throwable $e): void
    {
        trigger_error(sprintf('%s: %s in %s:%s', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()), E_USER_WARNING);
    }
}
