<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\CacheStoreException;
use BEAR\QueryRepository\Log\Context\CacheErrorContext;
use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\ConditionalRequestContext;
use BEAR\Resource\AbstractUri;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Throwable;

use function hrtime;
use function is_string;
use function round;
use function sprintf;
use function trigger_error;

use const E_USER_WARNING;

/** @internal */
final class ConditionalRequest
{
    /**
     * Recorded as its own conditional_request scope: a hit is the 304 decision that no get
     * scope ever shows. No If-None-Match presents nothing, so nothing is recorded.
     *
     * @param array<array-key, mixed> $server
     */
    public static function isNotModified(
        ResourceStorageInterface $storage,
        SemanticLoggerInterface $logger,
        string $ifNoneMatch,
        array $server,
    ): bool {
        // What answering without running the resource cost: this is the whole request on a hit.
        $openId = $logger->open(new ConditionalRequestContext($ifNoneMatch));
        $start = hrtime(true);
        try {
            $hit = $storage->hasEtag($ifNoneMatch);
        } catch (CacheStoreException $e) {
            // An unreadable validator does not match: serve the request in full rather than
            // fail it with a 500 for a down ETag store.
            $cause = $e->getPrevious() ?? $e;
            $logger->event(new CacheErrorContext(self::requestUri($server), 'read', $cause->getMessage(), $cause::class));
            $logger->close(new CacheMissContext('etag', round((hrtime(true) - $start) / 1_000_000, 3)), $openId);
            self::triggerWarning($e);

            return false;
        }

        $durationMs = round((hrtime(true) - $start) / 1_000_000, 3);
        $logger->close($hit ? new CacheHitContext('etag', $durationMs) : new CacheMissContext('etag', $durationMs), $openId);

        return $hit;
    }

    /**
     * A validator issued for another URI is not this representation's, so the request is
     * answered in full.
     *
     * @param array<array-key, mixed> $server
     */
    public static function isNotModifiedFor(
        ResourceStorageInterface $storage,
        SemanticLoggerInterface $logger,
        AbstractUri $uri,
        string $ifNoneMatch,
        array $server,
    ): bool {
        if (! $storage instanceof ScopedValidatorInterface) {
            // A storage that cannot scope answers the older question; a reader of the log can
            // still see which validator was presented, but not which question was asked.
            return self::isNotModified($storage, $logger, $ifNoneMatch, $server);
        }

        $openId = $logger->open(new ConditionalRequestContext($ifNoneMatch));
        $start = hrtime(true);
        try {
            $hit = $storage->hasEtagFor($ifNoneMatch, $uri);
        } catch (CacheStoreException $e) {
            // The same decision as the unscoped answer, against the URI that was asked for.
            $cause = $e->getPrevious() ?? $e;
            $logger->event(new CacheErrorContext((string) $uri, 'read', $cause->getMessage(), $cause::class));
            $logger->close(new CacheMissContext('etag', round((hrtime(true) - $start) / 1_000_000, 3)), $openId);
            self::triggerWarning($e);

            return false;
        }

        $durationMs = round((hrtime(true) - $start) / 1_000_000, 3);
        $logger->close($hit ? new CacheHitContext('etag', $durationMs) : new CacheMissContext('etag', $durationMs), $openId);

        return $hit;
    }

    /**
     * The request URI when the SAPI array carries one
     *
     * The interface shape names only the validator key, but the real $_SERVER carries the
     * whole request; empty means no URI was resolvable at the transfer boundary.
     *
     * @param array<array-key, mixed> $server
     */
    private static function requestUri(array $server): string
    {
        /** @var mixed $uri */
        $uri = $server['REQUEST_URI'] ?? '';

        return is_string($uri) ? $uri : '';
    }

    /**
     * A failure on the cache path degrades to a warning rather than an exception
     *
     * The host's own monitoring is what notices a pool outage; the log records it at the
     * point it happened.
     */
    private static function triggerWarning(Throwable $e): void
    {
        $cause = $e->getPrevious() ?? $e;
        trigger_error(sprintf('%s: %s in %s:%s', $cause::class, $cause->getMessage(), $cause->getFile(), $cause->getLine()), E_USER_WARNING);
    }
}
