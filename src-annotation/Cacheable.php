<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

use BEAR\QueryRepository\CacheInterceptor;
use function is_string;

/**
 * @Annotation
 * @Target("CLASS")
 *
 * @see CacheInterceptor
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Cacheable
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
    public function __construct($expiry = 'never', int $expirySecond = 0, string $expiryAt = '', bool $update = false, string $type = 'value')
    {
        if (is_string($expiry)) {
            $this->expiry = $expiry;
            $this->expirySecond = $expirySecond;
            $this->expiryAt = $expiryAt;
            $this->update = $update;
            $this->type = $type;

            return;
        }
        /** @var array{expiry: string, expirySecond: int, expiryAt: string, bool: bool, type: string} $expiry */
        $value = $expiry;
        $this->expiry = $value['expiry'] ?? 'never';
        $this->expirySecond = $value['expirySecond'] ?? 0;
        $this->expiryAt = $value['expiryAt'] ?? '';
        $this->update = $value['update'] ?? true;
        $this->type = $value['type'] ?? 'value';
    }
}
