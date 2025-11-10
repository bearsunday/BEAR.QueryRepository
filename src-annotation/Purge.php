<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

/**
 * Purges cache for specified URI after command execution
 *
 * Example:
 * ```php
 * #[Purge(uri: 'app://self/user/profile?user_id={id}')]
 * #[Purge(uri: 'app://self/user/friend?user_id={id}')]
 * public function onDelete(int $id): static
 * ```
 *
 * Interceptors bound:
 * - RefreshInterceptor (when applied to non-Cacheable classes)
 * - CommandInterceptor (when applied to Cacheable classes)
 *
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#tag-based-cache-invalidation
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Purge extends AbstractCommand
{
}
