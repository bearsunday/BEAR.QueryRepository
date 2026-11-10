<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use WeakMap;

/**
 * The resource objects QueryRepository is storing, from their dependencies to their ETag
 *
 * @internal
 */
final class PutInProgress
{
    /** @var WeakMap<ResourceObject, true> */
    private WeakMap $resourceObjects;

    public function __construct()
    {
        $this->resourceObjects = self::none();
    }

    /**
     * A put never outlives its request, so a serialized injector starts with none
     *
     * @return array<never>
     */
    public function __serialize(): array
    {
        return [];
    }

    /** @param array<never> $data */
    public function __unserialize(array $data): void
    {
        $this->resourceObjects = self::none();
    }

    public function begin(ResourceObject $ro): void
    {
        $this->resourceObjects[$ro] = true;
    }

    public function end(ResourceObject $ro): void
    {
        unset($this->resourceObjects[$ro]);
    }

    public function has(ResourceObject $ro): bool
    {
        return isset($this->resourceObjects[$ro]);
    }

    /** @return WeakMap<ResourceObject, true> */
    private static function none(): WeakMap
    {
        /** @var WeakMap<ResourceObject, true> $none */
        $none = new WeakMap();

        return $none;
    }
}
