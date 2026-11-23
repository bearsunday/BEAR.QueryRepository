<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

/**
 * Caches the resource's value (its body), and with type: 'view' its view as well
 *
 * Example:
 * ```php
 * // Cache with predefined expiry preset
 * #[Cacheable(expiry: 'short')]  // short, medium, long, never
 * ```
 *
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#cacheable
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Cacheable
{
    /**
     * @param 'short'|'medium'|'long'|'never' $expiry
     * @param non-negative-int                $expirySecond
     * @param 'value'|'view'                  $type
     */
    public function __construct(
        public string $expiry = 'never',
        public int $expirySecond = 0,
        public string $expiryAt = '',
        public string $type = 'value',
    ) {
    }
}
