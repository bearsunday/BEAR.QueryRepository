<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

final class StorageRedisDsnModuleMarshallerTest extends TestCase
{
    public function testModuleBindings(): void
    {
        $module = new StorageRedisDsnModule(
            'redis://localhost:6379',
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
            'redis://localhost:6379',
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
        $module = new StorageRedisDsnModule('redis://localhost:6379', ['timeout' => 30]);

        $injector = new Injector($module);

        $adapter = $injector->getInstance(TagAwareAdapterInterface::class, ResourceObjectPool::class);
        $this->assertInstanceOf(TagAwareAdapterInterface::class, $adapter);
    }

    public function testMarshallerOptionsDefaultValues(): void
    {
        $module = new StorageRedisDsnModule('redis://localhost:6379');

        $injector = new Injector($module);

        $adapter = $injector->getInstance(TagAwareAdapterInterface::class, ResourceObjectPool::class);
        $this->assertInstanceOf(TagAwareAdapterInterface::class, $adapter);
    }

    public function testDisabledMarshallerOptions(): void
    {
        $module = new StorageRedisDsnModule(
            'redis://localhost:6379',
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
            'redis://localhost:6379',
            [],
            1800,
        );

        $injector = new Injector($module);

        $adapter = $injector->getInstance(TagAwareAdapterInterface::class, ResourceObjectPool::class);
        $this->assertInstanceOf(TagAwareAdapterInterface::class, $adapter);
    }
}
