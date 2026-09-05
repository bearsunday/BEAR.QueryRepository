<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use Override;

/** A bound DonutRepositoryInterface broken on one side, in a way the store never raised */
final class FakeDefectiveDonutRepository implements DonutRepositoryInterface
{
    /** @param 'read'|'write' $brokenSide */
    public function __construct(private string $brokenSide)
    {
    }

    #[Override]
    public function get(ResourceObject $ro): ResourceObject|null
    {
        if ($this->brokenSide === 'read') {
            throw new FakeRepositoryDefect('read is broken');
        }

        return null;
    }

    #[Override]
    public function putStatic(ResourceObject $ro, int|null $ttl = null, int|null $sMaxAge = null): ResourceObject
    {
        throw new FakeRepositoryDefect('write is broken');
    }

    #[Override]
    public function putDonut(ResourceObject $ro, int|null $donutTtl): ResourceObject
    {
        throw new FakeRepositoryDefect('write is broken');
    }

    #[Override]
    public function purge(AbstractUri $uri): void
    {
    }

    #[Override]
    public function invalidateTags(array $tags): void
    {
    }
}
