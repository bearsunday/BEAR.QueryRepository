<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
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
}
