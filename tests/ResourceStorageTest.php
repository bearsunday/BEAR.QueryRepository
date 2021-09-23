<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\Index;
use PHPUnit\Framework\TestCase;
use Ray\PsrCacheModule\FilesystemAdapter;

class ResourceStorageTest extends TestCase
{
    /** @var ResourceStorage */
    private $storage;

    /** @var Index */
    private $ro;

    protected function setUp(): void
    {
        $this->storage = new ResourceStorage(
            new FilesystemAdapter('', 0, $_ENV['TMP_DIR'])
        );
        $this->ro = new Index();
        $this->ro->uri = new Uri('page://self/user');
        $this->ro->body = [];
    }

    public function testSaveGetStatic(): void
    {
        $static = ResourceStatic::create($this->ro, new DonutRenderer());
        $this->storage->saveStatic($this->ro->uri, $static);
        $static = $this->storage->getStatic($this->ro->uri);
        $this->assertInstanceOf(ResourceStatic::class, $static);
    }
}
