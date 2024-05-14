<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

use BEAR\QueryRepository\CacheInterceptor;
use Doctrine\Common\Annotations\NamedArgumentConstructorAnnotation;
use function is_string;

/**
 * @Annotation
 * @Target("CLASS")
 *
 * @see CacheInterceptor
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Cacheable implements NamedArgumentConstructorAnnotation
{
    /**
     * @param 'short'|'medium'|'long'|'never' $expiry
     * @param 'value'|'view'                  $type
     */
    public function __construct(public string $expiry = 'never', public int $expirySecond = 0, public string $expiryAt = '', public bool $update = false, public string $type = 'value')
    {
    }
}
