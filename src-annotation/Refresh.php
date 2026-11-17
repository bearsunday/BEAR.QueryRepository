<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

/**
 * Purges the cache of the URI that `uri` expands to after the method runs, then requests it again
 *
 * `uri` is a URI template expanded with the body the method set and the request query.
 *
 * Example:
 * ```php
 * #[Refresh(uri: 'app://self/user/profile?user_id={id}')]
 * public function onPut(int $id, string $name): static
 * {
 *     // ...
 * }
 * ```
 *
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#invalidate-by-uri
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Refresh extends AbstractCommand
{
}
