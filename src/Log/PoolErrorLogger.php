<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use BEAR\QueryRepository\Log\Context\PoolErrorContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

use function in_array;
use function is_string;
use function str_contains;

/**
 * The cache pool's own report, in the cache log
 *
 * `symfony/cache` adapters do not throw: they log the backend failure and answer as if the entry
 * were absent. Given to the pools, this turns that report into a `pool_error` event, so a store
 * that is down is visible where every other cache decision already is.
 */
final class PoolErrorLogger extends AbstractLogger
{
    private const FAILED = ['error', 'critical', 'alert', 'emergency', 'warning'];

    public function __construct(
        #[CacheLog]
        private readonly SemanticLoggerInterface $logger = new NullSemanticLogger(),
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param mixed   $level
     * @param mixed[] $context
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (! is_string($level) || ! in_array($level, self::FAILED, true)) {
            return;
        }

        /** @var mixed $exception */
        $exception = $context['exception'] ?? null;
        /** @var mixed $key */
        $key = $context['key'] ?? '';

        $this->logger->event(new PoolErrorContext(
            is_string($key) ? $key : '',
            $this->operation((string) $message),
            $exception instanceof Throwable ? $exception->getMessage() : (string) $message,
            $exception instanceof Throwable ? $exception::class : 'unknown',
        ));
    }

    /**
     * Which side of the pool refused, as the adapter worded it.
     *
     * `unknown` rather than a guess: the wording belongs to `symfony/cache`, and a reader
     * aggregating write failures should not be handed a read that was mislabelled.
     *
     * @return 'read'|'write'|'unknown'
     */
    private function operation(string $message): string
    {
        if (str_contains($message, 'fetch') || str_contains($message, 'read')) {
            return 'read';
        }

        foreach (['save', 'write', 'delete', 'unlink', 'invalidate', 'clear', 'prune'] as $word) {
            if (str_contains($message, $word)) {
                return 'write';
            }
        }

        return 'unknown';
    }
}
