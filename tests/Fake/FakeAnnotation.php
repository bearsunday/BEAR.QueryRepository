<?php
namespace BEAR\QueryRepository;

use Attribute;

/**
 * @Annotation
 * @Target("METHOD")
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class FakeAnnotation
{
}
