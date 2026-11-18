<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\RefreshCache;
use Override;
use Ray\Aop\AbstractMatcher;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * Matches a write (onPut, onPost, onPatch, onDelete) with none of #[Purge], #[Refresh], #[RefreshCache] and
 * #[CacheableResponse]; it matches no class
 *
 * @internal
 */
final class UndeclaredWriteMatcher extends AbstractMatcher
{
    private readonly UnsafeMethodMatcher $write;
    private readonly CommandMethodMatcher $command;

    public function __construct()
    {
        parent::__construct();

        $this->write = new UnsafeMethodMatcher();
        $this->command = new CommandMethodMatcher();
    }

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
        return $this->write->matchesMethod($method, [])
            && ! $this->command->matchesMethod($method, [])
            && $method->getAttributes(RefreshCache::class, ReflectionAttribute::IS_INSTANCEOF) === []
            && $method->getAttributes(CacheableResponse::class, ReflectionAttribute::IS_INSTANCEOF) === [];
    }
}
