<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

/**
 * Caches the response (its representation) and gives it an ETag
 *
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#usage
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class CacheableResponse
{
}
