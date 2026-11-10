<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Entity;

use function array_map;

/** The rows each channel lists, as article and series ULID pairs */
final class Catalog
{
    /** @var array<string, list<array{0: string, 1: string}>> */
    public static array $channels = [];

    public static function reset(): void
    {
        self::$channels = ['c1' => [['a1', 's1'], ['a3', 's1']], 'c2' => [['a7', 's2']], 'empty' => []];
    }

    /** @return list<Item> */
    public static function items(string $channelId): array
    {
        return array_map(static fn (array $row): Item => new Item($row[0], $row[1]), self::$channels[$channelId] ?? []);
    }
}
