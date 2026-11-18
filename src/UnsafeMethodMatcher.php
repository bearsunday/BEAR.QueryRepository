<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;
use Ray\Aop\AbstractMatcher;
use ReflectionClass;
use ReflectionMethod;

use function in_array;
use function strtolower;

/**
 * Matches a resource's unsafe methods, the write handlers BEAR.Resource dispatches to: onPut, onPost, onPatch and onDelete
 *
 * A method matcher; it matches no class.
 *
 * @internal
 */
final class UnsafeMethodMatcher extends AbstractMatcher
{
    /** Lowercased, as PHP method names are case-insensitive */
    private const WRITES = ['onput', 'onpost', 'onpatch', 'ondelete'];

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function matchesClass(ReflectionClass $class, array $arguments): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function matchesMethod(ReflectionMethod $method, array $arguments): bool
    {
        return in_array(strtolower($method->name), self::WRITES, true);
    }
}
