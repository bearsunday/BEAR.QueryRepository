<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

/**
 * Refreshes cache for specified URI after command execution
 *
 * Interceptors bound:
 * - RefreshInterceptor (when applied to non-Cacheable classes)
 * - CommandInterceptor (when applied to Cacheable classes)
 *
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#event-driven-content
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Refresh extends AbstractCommand
{
}
