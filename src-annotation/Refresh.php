<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

/**
 * Refreshes cache for specified URI after command execution
 *
 * Behavior differs based on class attributes:
 * - **#[Cacheable] classes**: CommandInterceptor automatically binds to all
 *   command methods (onPut/onPatch/onDelete) and processes #[Refresh] annotations
 * - **Non-Cacheable classes**: RefreshInterceptor binds only to methods explicitly
 *   marked with #[Purge] or #[Refresh]
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
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#event-driven-content
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Refresh extends AbstractCommand
{
}
