<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\Index;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class ResourceStorageTest extends TestCase
{
    private ResourceStorage $storage;
    private Index $ro;

    protected function setUp(): void
    {
        $this->storage = new ResourceStorage(
            new RepositoryLogger(),
            new NullPurger(),
            new UriTag(),
            new FilesystemAdapter('', 0, $_ENV['TMP_DIR']),
        );
        $this->ro = new Index();
        $this->ro->uri = new Uri('page://self/user');
        $this->ro->body = [];
    }

    public function testSaveGetStatic(): void
    {
        $donut = ResourceDonut::create($this->ro, new DonutRenderer(), new SurrogateKeys(new Uri('app://self/')), null, false);
        $this->storage->saveDonut($this->ro->uri, $donut, null, []);
        $donut = $this->storage->getDonut($this->ro->uri);
        $this->assertInstanceOf(ResourceDonut::class, $donut);
    }
}
