<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

/** @requires extension redis */
final class StorageRedisDsnModuleMarshallerTest extends TestCase
{
    use RequiresRedisServerTrait;

    protected function setUp(): void
    {
        self::skipWithoutRedisServer();
    }

    public function testModuleBindings(): void
    {
        $module = new StorageRedisDsnModule(
            self::redisDsn(),
            ['timeout' => 30],
            3600,
            [
                'enabled' => true,
                'type' => 'default',
                'use_igbinary' => false,
            ],
        );

        $injector = new Injector($module);

        $adapter = $injector->getInstance(TagAwareAdapterInterface::class, ResourceObjectPool::class);
        $this->assertInstanceOf(TagAwareAdapterInterface::class, $adapter);
    }

    public function testMarshallerProviderBinding(): void
    {
        $module = new StorageRedisDsnModule(
            self::redisDsn(),
            [],
            3600,
            [
                'enabled' => true,
                'type' => 'default',
                'use_igbinary' => false,
            ],
        );

        $injector = new Injector($module);
        $provider = $injector->getInstance(MarshallerProvider::class);

        $this->assertInstanceOf(MarshallerProvider::class, $provider);
    }

    public function testBackwardCompatibilityBindings(): void
    {
        $module = new StorageRedisDsnModule(self::redisDsn(), ['timeout' => 30]);

        $injector = new Injector($module);

        $adapter = $injector->getInstance(TagAwareAdapterInterface::class, ResourceObjectPool::class);
        $this->assertInstanceOf(TagAwareAdapterInterface::class, $adapter);
    }

    public function testMarshallerOptionsDefaultValues(): void
    {
        $module = new StorageRedisDsnModule(self::redisDsn());

        $injector = new Injector($module);

        $adapter = $injector->getInstance(TagAwareAdapterInterface::class, ResourceObjectPool::class);
        $this->assertInstanceOf(TagAwareAdapterInterface::class, $adapter);
    }

    public function testDisabledMarshallerOptions(): void
    {
        $module = new StorageRedisDsnModule(
            self::redisDsn(),
            [],
            0,
            ['enabled' => false],
        );

        $injector = new Injector($module);

        $adapter = $injector->getInstance(TagAwareAdapterInterface::class, ResourceObjectPool::class);
        $this->assertInstanceOf(TagAwareAdapterInterface::class, $adapter);
    }

    public function testDefaultLifetimeParameter(): void
    {
        $module = new StorageRedisDsnModule(
            self::redisDsn(),
            [],
            1800,
        );

        $injector = new Injector($module);

        $adapter = $injector->getInstance(TagAwareAdapterInterface::class, ResourceObjectPool::class);
        $this->assertInstanceOf(TagAwareAdapterInterface::class, $adapter);
    }
}
