<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Override;
use Throwable;

/** Production BodySerializationErrorHandlerInterface, bound by ProdQueryRepositoryModule: lets the response go out uncached. */
final class SkipSerializationError implements BodySerializationErrorHandlerInterface
{
    #[Override]
    public function __invoke(ResourceObject $ro, Throwable $e): void
    {
    }
}
