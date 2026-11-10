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
    /** @var WeakMap<ResourceObject, int> */
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

    /**
     * @param array<never> $data
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function __unserialize(array $data): void
    {
        $this->resourceObjects = self::none();
    }

    /** Every begin() needs its own end() before the mark is gone */
    public function begin(ResourceObject $ro): void
    {
        $this->resourceObjects[$ro] = $this->depth($ro) + 1;
    }

    public function end(ResourceObject $ro): void
    {
        $depth = $this->depth($ro) - 1;
        if ($depth > 0) {
            $this->resourceObjects[$ro] = $depth;

            return;
        }

        unset($this->resourceObjects[$ro]);
    }

    public function has(ResourceObject $ro): bool
    {
        return isset($this->resourceObjects[$ro]);
    }

    private function depth(ResourceObject $ro): int
    {
        $depth = $this->resourceObjects[$ro] ?? null;

        return $depth ?? 0;
    }

    /** @return WeakMap<ResourceObject, int> */
    private static function none(): WeakMap
    {
        /** @var WeakMap<ResourceObject, int> $none */
        $none = new WeakMap();

        return $none;
    }
}
