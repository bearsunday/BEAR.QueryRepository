<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Override;
use Throwable;

/**
 * Production BodySerializationErrorHandlerInterface: lets the response go out uncached
 *
 * Bound by ProdQueryRepositoryModule. Returning without throwing is the whole contract - the
 * caller (HeaderSetter) strips the CDN-facing headers, rewrites Cache-Control to private and
 * skips the store on every return, regardless of which handler returned; this implementation
 * adds nothing further.
 */
final class SkipBodySerializationError implements BodySerializationErrorHandlerInterface
{
    #[Override]
    public function __invoke(ResourceObject $ro, Throwable $e): void
    {
    }
}
