<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * The marshalling options of `StorageRedisDsnModule`: `enabled`, `type` ('default' or 'deflate') and `use_igbinary`
 */
#[Attribute]
#[Qualifier]
final class MarshallerOptions
{
}
