<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use ArrayIterator;
use BEAR\Resource\ResourceObject;
use IteratorAggregate;
use Traversable;

use function array_is_list;
use function array_key_exists;
use function is_array;
use function is_iterable;
use function is_object;

/**
 * The rows a #[DependsOn] declaration reads: those under its key, or the body itself without one
 *
 * @internal
 */
final class DependsOnRows
{
    /**
     * Each array or object of a list, or the array or object itself, read as `(array)`. A Traversable that may not rewind is read
     * once and put back as an ArrayIterator: {@see self::readOnce()}.
     *
     * @return list<array<array-key, mixed>>
     */
    public function of(ResourceObject $ro, string|null $key): array
    {
        /** @var mixed $value */
        $value = $this->value($ro, $key);
        $isList = is_array($value) ? array_is_list($value) : $value instanceof Traversable;
        if (! $isList) {
            return is_array($value) || is_object($value) ? [(array) $value] : [];
        }

        $rows = [];
        /** @var mixed $row */
        foreach (is_iterable($value) ? $value : [] as $row) {
            if (is_array($row) || is_object($row)) {
                $rows[] = (array) $row;
            }
        }

        return $rows;
    }

    /** The value under the key, or the body without one, put back read once when it may not rewind */
    private function value(ResourceObject $ro, string|null $key): mixed
    {
        /** @var mixed $value */
        $value = $key === null ? $ro->body : (is_array($ro->body) && array_key_exists($key, $ro->body) ? $ro->body[$key] : null);
        if (! $value instanceof Traversable || $value instanceof IteratorAggregate || $value instanceof ArrayIterator) {
            return $value;
        }

        $value = $this->readOnce($value);
        if ($key === null) {
            $ro->body = $value;

            return $value;
        }

        /** @var array<array-key, mixed> $body the key was read from it */
        $body = $ro->body;
        $body[$key] = $value;
        $ro->body = $body;

        return $value;
    }

    /**
     * @param Traversable<mixed, mixed> $iterator
     *
     * @return ArrayIterator<array-key, mixed> each value under the key it was yielded with, or as a list when a key repeats, as
     *                                         `yield from` gives, so that no row is lost
     */
    private function readOnce(Traversable $iterator): ArrayIterator
    {
        $keyed = [];
        $list = [];
        $unique = true;
        /**
         * @var array-key $key
         * @var mixed     $value
         */
        foreach ($iterator as $key => $value) {
            $unique = $unique && ! array_key_exists($key, $keyed);
            /** @psalm-suppress MixedAssignment */
            $keyed[$key] = $list[] = $value;
        }

        return new ArrayIterator($unique ? $keyed : $list);
    }
}
