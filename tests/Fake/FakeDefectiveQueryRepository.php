<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use Override;

/** A bound QueryRepositoryInterface whose read is broken rather than its store */
final class FakeDefectiveQueryRepository implements QueryRepositoryInterface
{
    #[Override]
    public function put(ResourceObject $ro)
    {
        return true;
    }

    #[Override]
    public function get(AbstractUri $uri): ResourceState|null
    {
        throw new FakeRepositoryDefect('read is broken');
    }

    #[Override]
    public function purge(AbstractUri $uri)
    {
        return true;
    }
}
