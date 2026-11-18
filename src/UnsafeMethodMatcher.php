<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;
use Ray\Aop\AbstractMatcher;
use ReflectionClass;
use ReflectionMethod;

use function str_starts_with;

/**
 * Matches a resource's unsafe methods, the writes: onPut, onPost, onPatch and onDelete
 *
 * A method matcher; it matches no class.
 *
 * @internal
 */
final class UnsafeMethodMatcher extends AbstractMatcher
{
    private const PREFIXES = ['onPut', 'onPost', 'onPatch', 'onDelete'];

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
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($method->name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
