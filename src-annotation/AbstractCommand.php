<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\RepositoryModule\Annotation;

/**
 * @Annotation
 * @Target("METHOD")
 */
abstract class AbstractCommand
{
    /**
     * @var string
     */
    public $uri = false;
}
