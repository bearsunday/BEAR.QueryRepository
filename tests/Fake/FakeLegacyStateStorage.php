<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;

use function gmdate;
use function time;

/**
 * Storage holding a state saved before ResourceState::$storedAt existed
 *
 * A warm cache surviving a deploy: the property unserializes as null, so the age of the
 * entry can only be derived from the content's change time. Nothing stores such a state
 * through the public API anymore, hence this fake.
 */
final class FakeLegacyStateStorage implements ResourceStorageInterface
{
    /** How long ago the stored content last changed */
    public const RESIDENCE = 3600;

    public function get(AbstractUri $uri): ResourceState|null
    {
        $ro = new class extends ResourceObject{
        };
        $ro->uri = new Uri('app://self/user?id=1');
        $ro->headers[Header::LAST_MODIFIED] = gmdate(Header::RFC7231, time() - self::RESIDENCE);
        $state = ResourceState::create($ro, ['id' => 1], null);
        $state->storedAt = null;

        return $state;
    }

    public function hasEtag(string $etag): bool
    {
    }

    public function saveEtag(AbstractUri $uri, string $etag, string $surrogateKeys, int|null $ttl): void
    {
    }

    public function deleteEtag(AbstractUri $uri)
    {
    }

    public function saveValue(ResourceObject $ro, int $ttl)
    {
    }

    public function saveView(ResourceObject $ro, int $ttl)
    {
    }

    public function getDonut(AbstractUri $uri): ResourceDonut|null
    {
    }

    public function saveDonut(AbstractUri $uri, ResourceDonut $donut, int|null $sMaxAge, array $headerKeys): void
    {
    }

    public function saveDonutView(ResourceObject $ro, int|null $ttl): bool
    {
    }

    public function invalidateTags(array $tags): bool
    {
    }
}
