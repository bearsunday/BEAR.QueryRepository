<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\RepositoryModule\Annotation;

/**
 * @Annotation
 * @Target("METHOD")
 */
final class Purge
{
    /**
     * @var string
     */
    public $uri = false;
}
