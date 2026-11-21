<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\CacheStoreException;
use BEAR\QueryRepository\Log\Context\CacheErrorContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Throwable;

use function sprintf;
use function trigger_error;

use const E_USER_WARNING;

/** @internal */
final readonly class CacheFailure
{
    public function __construct(
        #[CacheLog]
        private SemanticLoggerInterface $logger,
    ) {
    }

    /**
     * Records a failure of the cache path. One the store caused is degraded to a warning, so the request is still
     * served; anything else is thrown again.
     *
     * @param 'read'|'write' $operation
     */
    public function degrade(string $uri, string $operation, Throwable $e): void
    {
        $cause = $e instanceof CacheStoreException ? $e->getPrevious() ?? $e : $e;
        $this->logger->event(new CacheErrorContext($uri, $operation, $cause->getMessage(), $cause::class));
        if (! $e instanceof CacheStoreException) {
            throw $e;
        }

        trigger_error(sprintf('%s: %s in %s:%s', $cause::class, $cause->getMessage(), $cause->getFile(), $cause->getLine()), E_USER_WARNING);
    }
}
