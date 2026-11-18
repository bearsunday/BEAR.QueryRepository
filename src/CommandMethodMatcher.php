<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
use Override;
use Ray\Aop\AbstractMatcher;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * Matches a method that names what its write invalidates: #[Purge] or #[Refresh]
 *
 * A method matcher; it matches no class.
 *
 * @internal
 */
final class CommandMethodMatcher extends AbstractMatcher
{
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
        return $method->getAttributes(Purge::class, ReflectionAttribute::IS_INSTANCEOF) !== []
            || $method->getAttributes(Refresh::class, ReflectionAttribute::IS_INSTANCEOF) !== [];
    }
}
