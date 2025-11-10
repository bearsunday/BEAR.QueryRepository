<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

/**
 * Purges cache for specified URI after command execution
 *
 * Behavior differs based on class attributes:
 * - **#[Cacheable] classes**: CommandInterceptor automatically binds to all
 *   command methods (onPut/onPatch/onDelete) and processes #[Purge] annotations
 * - **Non-Cacheable classes**: RefreshInterceptor binds only to methods explicitly
 *   marked with #[Purge] or #[Refresh]
 *
 * Example:
 * ```php
 * #[Purge(uri: 'app://self/user/profile?user_id={id}')]
 * #[Purge(uri: 'app://self/user/friend?user_id={id}')]
 * public function onDelete(int $id): static
 * ```
 *
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#tag-based-cache-invalidation
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Purge extends AbstractCommand
{
}
