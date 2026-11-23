<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Exception;

use function sprintf;

/**
 * Thrown before a write when the resource being stored declares a Cache-Control with `private` or `no-store`
 *
 * Remove the server-side cache attribute, or the declaration. Nothing is stored.
 */
final class UnstorableDeclaration extends LogicException
{
    public function __construct(
        public readonly string $uri,
        public readonly string $cacheControl,
    ) {
        parent::__construct(sprintf('uri %s, Cache-Control %s', $uri, $cacheControl));
    }
}
