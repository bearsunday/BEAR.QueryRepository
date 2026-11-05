<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\BodySerializationFailure;
use BEAR\Resource\ResourceObject;
use Override;
use Throwable;

/**
 * Default BodySerializationErrorHandlerInterface: fails the request
 *
 * Bound by QueryRepositoryModule. A body that cannot be serialized is a programming error
 * meant to be found while developing, where a 500 leads straight to the object that has to
 * become serializable; see SkipBodySerializationError for the production policy.
 */
final class ThrowBodySerializationError implements BodySerializationErrorHandlerInterface
{
    #[Override]
    public function __invoke(ResourceObject $ro, Throwable $e): void
    {
        throw BodySerializationFailure::from($ro, $e);
    }
}
