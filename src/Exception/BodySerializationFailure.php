<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Exception;

use BEAR\Resource\ResourceObject;
use Throwable;

use function sprintf;

/**
 * A put-time body serialize() refusal, thrown by ThrowBodySerializationError
 *
 * Not a CacheStoreFailure, which CacheInterceptor degrades to a warning.
 */
final class BodySerializationFailure extends RuntimeException
{
    public static function from(ResourceObject $ro, Throwable $e): self
    {
        return new self(sprintf('%s: %s', (string) $ro->uri, $e->getMessage()), (int) $e->getCode(), $e);
    }
}
