<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * The marshalling options of `StorageRedisDsnModule`: `enabled`, `type` ('default' or 'deflate') and `use_igbinary`
 *
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#redis-marshaller
 */
#[Attribute]
#[Qualifier]
final class MarshallerOptions
{
}
