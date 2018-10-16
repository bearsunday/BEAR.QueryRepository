<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

/**
 * @Annotation
 * @Target("CLASS")
 */
final class Cacheable
{
    /**
     * @var string
     * @Enum({"short", "medium", "long", "never"})
     */
    public $expiry = 'never';

    /**
     * @var int
     */
    public $expirySecond;

    /**
     * @var string
     */
    public $expiryAt = '';

    /**
     * @var bool
     */
    public $update = true;

    /**
     * @var string
     * @Enum({"value", "view"})
     */
    public $type = 'value';
}
