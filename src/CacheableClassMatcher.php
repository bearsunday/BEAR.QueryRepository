<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use Override;
use Ray\Aop\AbstractMatcher;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * Matches a class that stores its whole response, #[Cacheable] or #[CacheableResponse]: its writes refresh what it stores
 *
 * A class matcher; it matches no method.
 *
 * @internal
 */
final class CacheableClassMatcher extends AbstractMatcher
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    public function matchesClass(ReflectionClass $class, array $arguments): bool
    {
        return $class->getAttributes(Cacheable::class, ReflectionAttribute::IS_INSTANCEOF) !== []
            || $class->getAttributes(CacheableResponse::class, ReflectionAttribute::IS_INSTANCEOF) !== [];
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function matchesMethod(ReflectionMethod $method, array $arguments): bool
    {
        return false;
    }
}
