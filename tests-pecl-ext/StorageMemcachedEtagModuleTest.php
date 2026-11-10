<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use _PHPStan_c875e8309\Nette\InvalidArgumentException;
use BEAR\RepositoryModule\Annotation\EtagPool;
use Memcached;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheException;
use Psr\Cache\CacheItemPoolInterface;
use Ray\Di\Injector;
use Ray\PsrCacheModule\Annotation\Shared;
use Ray\PsrCacheModule\MemcachedAdapter;
use ReflectionProperty;
use Symfony\Component\Cache\Adapter\MemcachedAdapter as SymfonyMemcachedAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

use function array_map;

class StorageMemcachedEtagModuleTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('memcached') || version_compare(phpversion('memcached'), '2.2.0', '<')) {
            $this->markTestSkipped('Memcached extension >= 2.2.0 is required');
        }
    }

    public function testNew(): void
    {
        // @see http://php.net/manual/en/memcached.addservers.php
        $servers = 'mem1.domain.com:11211:33,mem2.domain.com:11211:67';
        $cache = (new Injector(new StorageMemcachedEtagModule($servers)))->getInstance(CacheItemPoolInterface::class, EtagPool::class);
        $this->assertInstanceOf(MemcachedAdapter::class, $cache);
    }

    public function testInvalidServerString(): void
    {
        $this->expectException(CacheException::class);
        $servers = 'invalid:server:string';
        $cache = (new Injector(new StorageMemcachedEtagModule($servers)))
            ->getInstance(CacheItemPoolInterface::class, EtagPool::class);
        assert($cache instanceof CacheItemPoolInterface);
        $cache->clear();
    }

    /** @return iterable<string, array{bool}> */
    public static function overrideOrder(): iterable
    {
        yield 'ETag module last' => [true];
        yield 'ETag module first' => [false];
    }

    /** No server is contacted: the pools are compared by the server list each client was given. */
    #[DataProvider('overrideOrder')]
    public function testResourceStorageKeepsEtagsInTheirOwnMemcached(bool $etagLast): void
    {
        $module = new QueryRepositoryModule();
        $ro = new StorageMemcachedModule('ro.example:11211');
        $etag = new StorageMemcachedEtagModule('etag.example:11211');
        $module->override($etagLast ? $ro : $etag);
        $module->override($etagLast ? $etag : $ro);
        $storage = (new Injector($module, __DIR__ . '/tmp'))->getInstance(ResourceStorageInterface::class);

        $roPool = (new ReflectionProperty(ResourceStorage::class, 'roPool'))->getValue($storage);
        $etagPool = (new ReflectionProperty(ResourceStorage::class, 'etagPool'))->getValue($storage);

        $this->assertNotSame($roPool, $etagPool);
        $this->assertSame(['ro.example:11211'], $this->servers($roPool));
        $this->assertSame(['etag.example:11211'], $this->servers($etagPool));
    }

    /** @return list<string> */
    private function servers(object $tagAwarePool): array
    {
        $this->assertInstanceOf(TagAwareAdapter::class, $tagAwarePool);
        $items = (new ReflectionProperty(TagAwareAdapter::class, 'pool'))->getValue($tagAwarePool);
        $this->assertInstanceOf(MemcachedAdapter::class, $items);
        $client = (new ReflectionProperty(SymfonyMemcachedAdapter::class, 'client'))->getValue($items);
        assert($client instanceof Memcached);

        return array_map(static fn (array $server): string => $server['host'] . ':' . $server['port'], $client->getServerList());
    }
}
