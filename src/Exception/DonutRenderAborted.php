<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Exception;

/**
 * A donut template or hole render threw while `BEAR\Resource\ResourceObject::__toString()`
 * swallowed the Throwable into an E_USER_WARNING and an empty string - that boundary never
 * hands the original Throwable back, only the warning its catch block leaves behind.
 *
 * `ResourceDonut::create()` detects that specific warning and raises this, carrying the
 * swallowed exception's class and message, as the cause passed to `CacheStoreFailure::from()` -
 * so the empty template is refused through the same graceful-degrade path a storage-boundary
 * failure uses, instead of being cached and served to every later visitor.
 */
final class DonutRenderAborted extends RuntimeException
{
}
