<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use Override;

/** A bound QueryRepositoryInterface of an application's own: it records each purge it is given */
final class FakePurgeRecordingQueryRepository implements QueryRepositoryInterface
{
    /** @var list<string> */
    public static array $purged = [];

    #[Override]
    public function put(ResourceObject $ro)
    {
        return true;
    }

    #[Override]
    public function get(AbstractUri $uri): ResourceState|null
    {
        return null;
    }

    #[Override]
    public function purge(AbstractUri $uri)
    {
        self::$purged[] = (string) $uri;

        return true;
    }
}
