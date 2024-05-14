<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use Doctrine\Common\Annotations\NamedArgumentConstructorAnnotation;
use Ray\Di\Di\Qualifier;

/**
 * @Annotation
 * @Target("METHOD")
 * @Qualifier()
 */
#[Attribute(Attribute::TARGET_METHOD), Qualifier]
final class Memcache implements NamedArgumentConstructorAnnotation
{
    public function __construct(public string $value)
    {
    }
}
