<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Exception;

use BEAR\Resource\ResourceObject;
use Throwable;

use function sprintf;

/**
 * A #[Cacheable] resource's body held a value serialize() refuses (a closure, a PDO
 * connection, an HTTP client), raised by the default BodySerializationErrorHandlerInterface
 *
 * Deliberately not a CacheStoreFailure: that type is caught and degraded by CacheInterceptor,
 * which would turn this into a silent per-request drop instead of the fail-fast ThrowBodySerializationError
 * promises. The original message - serialize()'s own, naming the class it refused - is kept
 * verbatim and the previous exception chained.
 */
final class BodySerializationFailure extends RuntimeException
{
    public static function from(ResourceObject $ro, Throwable $e): self
    {
        return new self(sprintf('%s: %s', (string) $ro->uri, $e->getMessage()), (int) $e->getCode(), $e);
    }
}
