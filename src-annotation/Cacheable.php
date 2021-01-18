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
     * @var 'short'|'medium'|'long'|'never'
     * @Enum({"short", "medium", "long", "never"})
     */
    public $expiry;

    /**
     * @var int
     */
    public $expirySecond;

    /**
     * @var string
     */
    public $expiryAt;

    /**
     * @var bool
     */
    public $update;

    /**
     * @var 'value'|'view'
     * @Enum({"value", "view"})
     */
    public $type;

    /**
     * @param 'short'|'medium'|'long'|'never' $expiry
     * @param 'value'|'view'                  $type
     */
    public function __construct(string $expiry = 'never', int $expirySecond = 0, string $expiryAt = '', bool $update = false, string $type = 'value')
    {
        $this->expiry = $expiry;
        $this->expirySecond = $expirySecond;
        $this->expiryAt = $expiryAt;
        $this->update = $update;
        $this->type = $type;
    }
}
