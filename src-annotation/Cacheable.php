<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
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
     * @var bool
     */
    public $update = true;

    /**
     * @var string
     * @Enum({"value", "view"})
     */
    public $type = 'value';
}
