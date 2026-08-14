<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use RuntimeException;

/** A rendering failure raised while the store renders the view (stands in for a missing Twig template) */
final class FakeTemplateNotFound extends RuntimeException
{
}
