<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

/**
 * Purges the cache of the URI that `uri` expands to after the method runs
 *
 * `uri` is a URI template expanded with the body the method set and the request query.
 *
 * Example:
 * ```php
 * #[Purge(uri: 'app://self/user/profile?user_id={id}')]
 * #[Purge(uri: 'app://self/user/friend?user_id={id}')]
 * public function onDelete(int $id): static
 * {
 *     // ...
 * }
 * ```
 *
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#invalidate-by-uri
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Purge extends AbstractCommand
{
}
