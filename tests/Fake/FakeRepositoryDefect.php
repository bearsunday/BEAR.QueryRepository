<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use RuntimeException;

/** A defect in a bound repository implementation - not a store failure, so nothing may swallow it */
final class FakeRepositoryDefect extends RuntimeException
{
}
