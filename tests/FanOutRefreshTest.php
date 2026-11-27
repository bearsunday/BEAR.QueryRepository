<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\CacheStoreException;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\App\FlakyTagPage;
use FakeVendor\HelloWorld\Resource\App\TagIndex;
use FakeVendor\HelloWorld\Resource\App\TagPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;

use function array_keys;
use function in_array;

/** A list given to an exploded template variable invalidates one URI per element */
class FanOutRefreshTest extends TestCase
{
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        $injector = new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        TagPage::$version = 1;
        TagPage::$gets = 0;
        TagIndex::$version = 1;
        FlakyTagPage::$version = 1;
        FlakyTagPage::$failOn = null;

        parent::setUp();
    }

    /** @return array<string, array{string, string, array<string, mixed>, array<string, array<string, string|int>>, list<string>}> */
    public static function writes(): array
    {
        $abcd = ['a' => ['id' => 'a'], 'b' => ['id' => 'b'], 'c' => ['id' => 'c'], 'd' => ['id' => 'd']];

        return [
            'Purge on a class that stores nothing' => ['app://self/fan-out-writer', 'post', ['id' => ['a', 'b', 'c']], $abcd, ['a', 'b', 'c']],
            'Refresh on a class that stores nothing' => ['app://self/fan-out-refresh-writer', 'post', ['id' => ['a', 'b', 'c']], $abcd, ['a', 'b', 'c']],
            'Purge on a #[Cacheable] class' => ['app://self/cacheable-fan-out-writer', 'post', ['id' => ['a', 'b', 'c']], $abcd, ['a', 'b', 'c']],
            'Refresh on a #[Cacheable] class' => ['app://self/cacheable-fan-out-writer', 'put', ['id' => ['a', 'b', 'c']], $abcd, ['a', 'b', 'c']],
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
            'empty list on a class that stores nothing' => ['app://self/fan-out-writer', 'post', ['id' => []], $abcd, []],
            'empty list on a #[Cacheable] class' => ['app://self/cacheable-fan-out-writer', 'post', ['id' => []], $abcd, []],
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

    public function testRepeatedElementIsRefreshedOnce(): void
    {
        $this->resource->post('app://self/fan-out-refresh-writer', ['id' => ['a', 'a', 'b']]);

        $this->assertSame(2, TagPage::$gets);
    }

    public function testNullElementInvalidatesNothing(): void
    {
        $this->resource->get('app://self/tag-index');
        $this->resource->get('app://self/tag-index', ['page' => 2]);
        $this->resource->get('app://self/tag-index', ['page' => 3]);

        $this->resource->put('app://self/fan-out-refresh-writer', ['page' => [null, 2]]);

        $this->assertSame(['v' => '1:v1'], $this->resource->get('app://self/tag-index')->body);
        $this->assertSame(['v' => '2:v2'], $this->resource->get('app://self/tag-index', ['page' => 2])->body);
        $this->assertSame(['v' => '3:v1'], $this->resource->get('app://self/tag-index', ['page' => 3])->body);
    }

    public function testExplodedPathStaysOneUri(): void
    {
        $before = $this->resource->get('app://self/user/profile', ['user_id' => 1])->body;

        $this->resource->post('app://self/exploded-path-writer', ['path' => ['user', 'profile']]);

        $this->assertNotSame($before, $this->resource->get('app://self/user/profile', ['user_id' => 1])->body);
    }

    public function testRefreshThatFailsMidwayLeavesNoStaleEntry(): void
    {
        foreach (['a', 'b', 'c'] as $id) {
            $this->resource->get('app://self/flaky-tag-page', ['id' => $id]);
        }

        FlakyTagPage::$failOn = 'b';
        try {
            $this->resource->post('app://self/flaky-refresh-writer', ['id' => ['a', 'b', 'c']]);
            $this->fail('the refresh did not fail');
        } catch (FakeRefreshFailure) {
        }

        FlakyTagPage::$failOn = null;
        $this->assertSame(['v' => 'c:v2'], $this->resource->get('app://self/flaky-tag-page', ['id' => 'c'])->body);
    }

    /**
     * @return array<string, array{string, string, int, string, bool}> uri, method, the GETs the write makes of the entries it names,
     *                                                                 the tag of the first URI purged, whether it reads its own URI again
     */
    public static function writesWhileTheCdnIsDown(): array
    {
        return [
            'Purge on a class that stores nothing' => ['app://self/fan-out-writer', 'post', 0, '_tag-page_id=a', false],
            'Refresh on a class that stores nothing' => ['app://self/fan-out-refresh-writer', 'post', 3, '_tag-page_id=a', false],
            'Purge on a #[Cacheable] class, after its own key' => ['app://self/cacheable-fan-out-writer', 'post', 0, '_cacheable-fan-out-writer_', true],
            'Refresh on a #[Cacheable] class, after its own key' => ['app://self/cacheable-fan-out-writer', 'put', 3, '_cacheable-fan-out-writer_', true],
        ];
    }

    /** A CDN purge that fails stops neither the other purges nor the reads: the first failure is thrown after them */
    #[DataProvider('writesWhileTheCdnIsDown')]
    public function testAFailedCdnPurgeLeavesNoStaleEntryAndIsThrownLast(string $uri, string $method, int $refreshed, string $firstTag, bool $rebuildsItself): void
    {
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(PurgerInterface::class)->to(FakeThrowingPurger::class);
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        foreach (['a', 'b', 'c'] as $id) {
            $resource->get('app://self/tag-page', ['id' => $id]);
        }

        try {
            $resource->{$method}($uri, ['id' => ['a', 'b', 'c']]);
            $this->fail('the failed CDN purge was not thrown');
        } catch (CacheStoreException $e) {
            $this->assertInstanceOf(FakeCdnPurgeFailed::class, $e->getPrevious());
            $this->assertStringStartsWith("purge failed: {$firstTag} ", $e->getPrevious()->getMessage(), 'the first failure');
        }

        $this->assertSame(3 + $refreshed, TagPage::$gets, 'each #[Refresh] URI is read');
        $this->assertSame($rebuildsItself, $injector->getInstance(ResourceStorageInterface::class)->get(new Uri($uri)) !== null, 'its own URI is read again where it is stored');
        foreach (['a', 'b', 'c'] as $id) {
            $this->assertSame(['v' => "{$id}:1:v2"], $resource->get('app://self/tag-page', ['id' => $id])->body, $id);
        }
    }
}
