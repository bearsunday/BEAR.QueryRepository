<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use FakeVendor\HelloWorld\Resource\App\TagIndex;
use FakeVendor\HelloWorld\Resource\App\TagPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function array_keys;
use function in_array;

/** A list given to an exploded template variable invalidates one URI per element */
class FanOutRefreshTest extends TestCase
{
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        $injector = new Injector(new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld')), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        TagPage::$version = 1;
        TagIndex::$version = 1;

        parent::setUp();
    }

    /** @return array<string, array{string, string, array<string, mixed>, array<string, array<string, string|int>>, list<string>}> */
    public static function writes(): array
    {
        $abcd = ['a' => ['id' => 'a'], 'b' => ['id' => 'b'], 'c' => ['id' => 'c'], 'd' => ['id' => 'd']];

        return [
            'Purge via RefreshInterceptor' => ['app://self/fan-out-writer', 'post', ['id' => ['a', 'b', 'c']], $abcd, ['a', 'b', 'c']],
            'Refresh via RefreshInterceptor' => ['app://self/fan-out-refresh-writer', 'post', ['id' => ['a', 'b', 'c']], $abcd, ['a', 'b', 'c']],
            'Purge via CommandInterceptor' => ['app://self/cacheable-fan-out-writer', 'post', ['id' => ['a', 'b', 'c']], $abcd, ['a', 'b', 'c']],
            'Refresh via CommandInterceptor' => ['app://self/cacheable-fan-out-writer', 'put', ['id' => ['a', 'b', 'c']], $abcd, ['a', 'b', 'c']],
            'variable under another key' => ['app://self/fan-out-writer', 'put', ['tagIds' => ['a', 'b', 'c']], $abcd, ['a', 'b', 'c']],
            'two lists in one expression' => [
                'app://self/fan-out-writer',
                'patch',
                ['id' => ['a', 'b'], 'page' => [1, 2]],
                [
                    'a1' => ['id' => 'a', 'page' => 1],
                    'a2' => ['id' => 'a', 'page' => 2],
                    'b1' => ['id' => 'b', 'page' => 1],
                    'b2' => ['id' => 'b', 'page' => 2],
                    'a3' => ['id' => 'a', 'page' => 3],
                    'c1' => ['id' => 'c', 'page' => 1],
                ],
                ['a1', 'a2', 'b1', 'b2'],
            ],
            'empty list via RefreshInterceptor' => ['app://self/fan-out-writer', 'post', ['id' => []], $abcd, []],
            'empty list via CommandInterceptor' => ['app://self/cacheable-fan-out-writer', 'post', ['id' => []], $abcd, []],
            'scalar' => ['app://self/fan-out-writer', 'post', ['id' => 'a'], $abcd, ['a']],
            'one-element list' => ['app://self/fan-out-writer', 'post', ['id' => ['a']], $abcd, ['a']],
            'associative array' => [
                'app://self/fan-out-writer',
                'post',
                ['id' => ['id' => 'a', 'page' => 2]],
                ['a2' => ['id' => 'a', 'page' => 2], 'a3' => ['id' => 'a', 'page' => 3]],
                ['a2'],
            ],
            'list without explode' => [
                'app://self/fan-out-writer',
                'delete',
                ['id' => ['a', 'b']],
                ['a,b' => ['id' => 'a,b'], 'a' => ['id' => 'a'], 'b' => ['id' => 'b']],
                ['a,b'],
            ],
        ];
    }

    /**
     * @param array<string, mixed>                     $query
     * @param array<string, array<string, string|int>> $entries cached before the write
     * @param list<string>                             $rebuilt keys of $entries the write invalidates
     */
    #[DataProvider('writes')]
    public function testWriteRebuildsTheEntriesItsTemplateNames(string $uri, string $method, array $query, array $entries, array $rebuilt): void
    {
        foreach ($entries as $entry) {
            $this->resource->get('app://self/tag-page', $entry);
        }

        $this->resource->{$method}($uri, $query);

        foreach (array_keys($entries) as $key) {
            $entry = $entries[$key];
            $version = in_array($key, $rebuilt, true) ? 2 : 1;
            $expected = $entry['id'] . ':' . ($entry['page'] ?? 1) . ':v' . $version;
            $this->assertSame(['v' => $expected], $this->resource->get('app://self/tag-page', $entry)->body, (string) $key);
        }
    }

    public function testEmptyListLeavesEveryVariantOfADefaultValuedDestination(): void
    {
        $this->resource->get('app://self/tag-index');
        $this->resource->get('app://self/tag-index', ['page' => 2]);

        $this->resource->put('app://self/fan-out-refresh-writer', ['page' => []]);

        $this->assertSame(['v' => '1:v1'], $this->resource->get('app://self/tag-index')->body);
        $this->assertSame(['v' => '2:v1'], $this->resource->get('app://self/tag-index', ['page' => 2])->body);
    }
}
