<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\BodySerializationFailure;
use BEAR\Resource\ResourceObject;
use Override;
use Throwable;

/** Default BodySerializationErrorHandlerInterface, bound by QueryRepositoryModule: fails the request. */
final class ThrowSerializationError implements BodySerializationErrorHandlerInterface
{
    #[Override]
    public function __invoke(ResourceObject $ro, Throwable $e): void
    {
        throw BodySerializationFailure::from($ro, $e);
    }
}
