<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\Exception\InvalidArgumentException;

/**
 * The pool is part of the graph an application builds at boot, and a machine that compiles the
 * application may have no store to reach.
 */
#[RequiresPhpExtension('redis')]
final class StorageRedisDsnModuleLazyTest extends TestCase
{
    /** A closed port: connection refused at once, no service to install. */
    private const UNREACHABLE = 'redis://127.0.0.1:1';

    public function testThePoolIsBuiltWithoutReachingTheStore(): void
    {
        $pool = $this->pool(new StorageRedisDsnModule(self::UNREACHABLE));

        $this->assertFalse($pool->getItem('key')->isHit(), 'the store is first reached on use, and its failure reads as a miss');
    }

    public function testLazyFalseConnectsWhenThePoolIsBuilt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->pool(new StorageRedisDsnModule(self::UNREACHABLE, ['lazy' => false]));
    }

    public function testLazyInTheDsnQueryWinsOverTheDefault(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->pool(new StorageRedisDsnModule(self::UNREACHABLE . '?lazy=0'));
    }

    private function pool(StorageRedisDsnModule $storage): TagAwareAdapterInterface
    {
        $module = new QueryRepositoryModule();
        $module->override($storage);

        return (new Injector($module))->getInstance(TagAwareAdapterInterface::class, ResourceObjectPool::class);
    }
}
