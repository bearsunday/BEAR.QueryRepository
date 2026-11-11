<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Entity;

use BEAR\RepositoryModule\Annotation\DependsOn;
use Stringable;

/** A row class whose subclasses inherit its declarations */
#[DependsOn(uri: 'app://self/rows/article?ulid={ulid}')]
#[DependsOn(uri: 'app://self/rows/taxonomy?ulid={seriesId}')]
abstract class AbstractContent
{
    public function __construct(
        public string|Stringable $ulid,
        public string|null $seriesId,
    ) {
    }
}
